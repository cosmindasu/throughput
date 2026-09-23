# docker/app/Dockerfile
# Imagine PHP-FPM pentru Throughput. Runtime 8.3 (composer.json → config.platform.php).
# Două target-uri, ambele referite explicit din docker-compose:
#   - development → docker-compose.yml (cod montat prin volum, `php artisan serve`)
#   - production   → docker-compose.coolify.yml (assets compilate, OPcache agresiv)

########################################
# Stage: base — extensii PHP comune ambelor target-uri
########################################
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        bash \
        libpq \
        icu-libs \
        # `icu-libs` e DOAR biblioteca; datele de locale stau separat, în `icu-data-full`.
        # Fără el, ICU rămâne cu bundle-ul stub (4 locale: root + en*), iar `NumberFormatter`
        # cade TĂCUT pe locale-ul root pentru orice altceva — `fr` formatează `1,234.50` în loc
        # de `1 234,50`. Nu dă eroare, nu dă warning: dă cifre englezești într-o interfață
        # franceză, în PDF-uri, exporturi, e-mailuri și mesaje de validare (FR-I18N-03).
        # CI-ul nu putea prinde asta — `shivammathur/setup-php` vine cu ICU complet, deci
        # suita e verde pe un runtime pe care imaginea NU îl reproducea. Măsurat la 2026-09-22:
        # 4 locale înainte, 906 după. Costă ~30 MB pe imagine; alternativa e franceza ruptă în
        # producție, în tăcere.
        icu-data-full \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        gd \
        intl \
        zip \
        bcmath \
        pcntl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Plafoanele de upload aliniate cu `client_max_body_size 32m` din docker/nginx/default.conf.
# Implicitele PHP (2M/8M) ar fi respins importurile CSV (IMPORT_MAX_ROWS=50000, Faza 4)
# cu o eroare la nivel de PHP, după ce nginx acceptase deja corpul cererii — două
# plafoane diferite în două straturi e exact tipul de nepotrivire greu de diagnosticat.
# `memory_limit` rămâne implicit (128M) în `conf.d`: 4 copii × plafon mai mare ar depăși
# cei 256m ai containerului `app` din §3.1. Vezi mai jos de ce `horizon` are nevoie de
# altceva și de ce nu poate primi altceva prin `conf.d`.
RUN { \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Plafon SEPARAT de memorie pentru containerul `horizon` (Faza 4, lot M — măsurat, nu
# presupus). Aceeași imagine servește trei containere cu bugete diferite: `app` 256m cu
# 4 copii FPM, `scheduler` 128m, `horizon` 384m. Un `memory_limit` în `conf.d` s-ar aplica
# tuturor trei, deci plafonul de care are nevoie DOAR workerul de coadă stă într-un director
# separat, activat prin `PHP_INI_SCAN_DIR` exclusiv pe serviciul `horizon`
# (docker-compose*.yml). Celelalte două rămân neatinse, la 128M.
#
# De ce era nevoie: `config/horizon.php` fixa `'memory' => 256` (pragul la care Horizon
# repornește workerul ÎNTRE joburi), cu bugetul containerului ca justificare — dar PHP
# omora procesul la 128M cu mult înainte, deci acel prag nu putea intra niciodată în
# vigoare. Un export PDF de 250 de rânduri pica cu „Allowed memory size exhausted", iar
# fatalul NU ajunge în `catch (Throwable)`: operația rămânea „running", cu polling la
# nesfârșit, până la epuizarea celor 3 încercări (~45 de minute).
#
# De ce `PHP_INI_SCAN_DIR` și nu `php -d memory_limit=… artisan horizon`: Horizon își
# pornește workerii ca procese PHP NOI, care nu moștenesc `-d` de pe comanda master.
# Variabila de mediu, da — verificat: master=256M, copil=256M.
#
# 256M, nu mai mult: workerul e unul singur (`maxProcesses => 1`), iar 256M de heap înseamnă
# ~290 MB RSS, plus ~60 MB ai supervisorului master — sub cei 384m ai containerului, cu marjă.
RUN mkdir -p /usr/local/etc/php/horizon.d \
    && echo 'memory_limit=256M' > /usr/local/etc/php/horizon.d/memory.ini

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]

########################################
# Stage: assets — build Vite (Node 22), folosit doar de target-ul `production`.
# În dezvoltare, assets-urile sunt servite de containerul `vite` separat
# (docker-compose.yml, `npm run dev`) — nu de această imagine.
########################################
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

########################################
# Stage: development — cod montat prin volum (docker-compose.yml), fără OPcache agresiv.
# `composer install` complet (cu dev deps) rulează la build, ca imaginea să fie
# funcțională și fără bind-mount; volumul montat la runtime îl suprascrie oricum.
########################################
FROM base AS development

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-interaction --prefer-dist

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

########################################
# Stage: production — autonomă, fără node_modules/surse Node în imaginea finală.
########################################
FROM base AS production

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && composer clear-cache

# public/build vine din stage-ul `assets` — fără el, JS/CSS dau 404 în producție.
COPY --from=assets /app/public/build /var/www/html/public/build

# OPcache agresiv doar în producție (dev rămâne pe validate_timestamps implicit = 1).
# memory_consumption=48 — vine direct din bugetul §3.1 (OPcache SHM ~48 MB din cei 256m ai app).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=48'; \
    } > /usr/local/etc/php/conf.d/opcache-production.ini \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
