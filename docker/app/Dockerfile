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
# `memory_limit` rămâne implicit (128M): 4 copii × plafon mai mare ar depăși cei 256m
# ai containerului `app` din §3.1.
RUN { \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

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
