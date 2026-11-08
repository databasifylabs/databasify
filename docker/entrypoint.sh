#!/bin/sh
set -eu

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

uses_local_redis() {
    [ -z "${REDIS_URL:-}" ] || return 1
    case "${REDIS_HOST:-127.0.0.1}" in
        127.0.0.1 | localhost | ::1) return 0 ;;
    esac
    return 1
}

own_redis_data() {
    mkdir -p /var/lib/redis
    chown -R redis:redis /var/lib/redis
}

run_processes() {
    export LOCAL_REDIS=false
    if uses_local_redis; then
        LOCAL_REDIS=true
        own_redis_data
    fi
    log "serving on port 80"
    exec supervisord --configuration /etc/supervisor/databasify.conf
}

[ "${1:-}" != --functions-only ] || return 0

cd /app

role=${1:-start}
[ "$#" -gt 0 ] && shift

case "$role" in
    start)
        require_app_key
        own_storage
        prepare_database
        cache_config
        run_processes
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
