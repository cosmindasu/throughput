# docker/nginx/Dockerfile
# Reverse proxy nginx → PHP-FPM (`app:9000`). Folosit doar de docker-compose.coolify.yml
# (fără nginx în dezvoltare — §3.5 din plan-implementare.md).
#
# Notă: nginx are nevoie de fișierele statice compilate (public/build) pe propriul
# filesystem, altfel try_files cade pe /index.php și Laravel răspunde 404 la JS/CSS.
# docker-compose.coolify.yml nu dă un `image:` explicit serviciului `app`, deci acest
# Dockerfile nu poate face `COPY --from=<imaginea app>` (nu există un tag stabil de
# referit înainte ca `app` să fie construit). Stage-ul `assets` de mai jos reproduce
# identic pasul de build din stage-ul `assets` al docker/app/Dockerfile, ca să obțină
# `public/build` independent de ordinea de build a serviciilor — vezi raportul final.

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# OPS-15 (audit infra 2026-09-23): pinuit la major.minor, ca toate celelalte imagini de
# bază din proiect (`php:8.3-fpm-alpine`, `node:22-alpine`, `postgres:16-alpine`,
# `redis:7-alpine`). Cu `nginx:alpine` simplu, un `docker build` fără cache la un deploy
# viitor putea trage o versiune majoră diferită, fără avertisment. Verificat local
# 2026-09-23: `nginx:alpine` și `nginx:1.31-alpine` rezolvă la ACELAȘI digest
# (`nginx/1.31.6`) — actualizează manual când versiunea stabilă avansează.
FROM nginx:1.31-alpine AS production

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Fișierele statice necompilate din public/ (favicon.ico, robots.txt) — la fel de
# necesare pe filesystem-ul nginx ca public/build, ca să nu dea 404.
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

EXPOSE 80
