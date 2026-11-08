#!/bin/sh
set -eu

cd /app

log() {
    printf '%s [databasify] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

require_app_key() {
    [ -n "${APP_KEY:-}" ] && return 0
    log "APP_KEY is missing: add it to /opt/databasify/.env, then start Databasify again"
    exit 1
}

as_app() {
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
}

own_storage() {
    mkdir -p /app/storage/app/private /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs
    chown -R www-data:www-data /app/storage /app/bootstrap/cache
}

prepare_database() {
    if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
        (as_app touch "${DB_DATABASE:-/app/storage/database.sqlite}")
    fi
    (as_app php artisan migrate --force --no-interaction)
}

cache_config() {
    (as_app php artisan optimize --no-interaction) > /dev/null
}

serve() {
    php-fpm8.5 --nodaemonize &
    fpm=$!
    nginx -g 'daemon off;' &
    web=$!
    trap 'kill -TERM "$fpm" "$web" 2>/dev/null || true' TERM INT
    while kill -0 "$fpm" 2>/dev/null && kill -0 "$web" 2>/dev/null; do
        sleep 1
    done
    kill -TERM "$fpm" "$web" 2>/dev/null || true
    wait
}

role=${1:-web}
[ "$#" -gt 0 ] && shift

case "$role" in
    web)
        require_app_key
        own_storage
        prepare_database
        cache_config
        log "serving on port 80"
        serve
        ;;
    horizon)
        require_app_key
        own_storage
        cache_config
        as_app php artisan horizon
        ;;
    scheduler)
        require_app_key
        own_storage
        cache_config
        as_app php artisan schedule:work
        ;;
    reverb)
        require_app_key
        own_storage
        cache_config
        as_app php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    healthcheck)
        exec curl --fail --silent --output /dev/null http://127.0.0.1/up
        ;;
    artisan)
        require_app_key
        own_storage
        as_app php artisan "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
