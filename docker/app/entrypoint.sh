#!/bin/bash
# docker/app/entrypoint.sh
#
# Rulează la pornirea containerului construit din docker/app/Dockerfile — folosit de
# `app`, `horizon` și `scheduler` (docker-compose.yml și docker-compose.coolify.yml),
# fiecare cu propriul `command:` final, pasat mai jos prin `exec "$@"`.
#
# Nu presupune că există `.env`: pe Coolify toate variabilele vin injectate direct în
# mediul containerului (§2 din plan-implementare.md, ultima regulă); local, `.env` e
# citit de Laravel din bind-mount (docker-compose.yml montează tot repo-ul).
set -euo pipefail

cd /var/www/html

if [ "${APP_RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "==> APP_RUN_MIGRATIONS=true — rulez migrațiile pe conexiunea de migrare (rolul cu BYPASSRLS — ADR-003)"
    php artisan migrate --force --database=pgsql_migrations
fi

if [ "${APP_RUN_SEEDERS:-false}" = "true" ]; then
    echo "==> APP_RUN_SEEDERS=true — rulez seederele"
    php artisan db:seed --force
fi

if [ "${APP_ENV:-}" = "production" ]; then
    echo "==> APP_ENV=production — config:cache / route:cache / view:cache"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
