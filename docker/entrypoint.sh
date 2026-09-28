#!/bin/sh
set -e

# storage/ is a bind mount from the host, so it may start empty.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs
[ -f storage/database.sqlite ] || touch storage/database.sqlite

# Only the web server warms the caches; one-off `docker compose run` commands
# (migrate, store:password...) pass through untouched.
if [ "$1" = "frankenphp" ]; then
    php artisan optimize
fi

exec "$@"
