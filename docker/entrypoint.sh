#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force --no-interaction >/dev/null 2>&1 || true
    APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)"
    export APP_KEY
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not configured. Set APP_KEY in .env or allow the entrypoint to generate it." >&2
    exit 1
fi

mkdir -p \
    database \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

touch database/database.sqlite
chown -R www-data:www-data storage bootstrap/cache database

php artisan migrate --force --no-interaction

php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache database

exec "$@"
