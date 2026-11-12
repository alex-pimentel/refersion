#!/usr/bin/env bash
set -euo pipefail

docker compose exec -T \
    -e APP_ENV=testing \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    -e QUEUE_CONNECTION=sync \
    -e MAIL_MAILER=array \
    app \
    php artisan test "$@"
