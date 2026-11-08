#!/bin/sh
set -eu

cd /app

SECRETS=/app/storage/databasify.env

. /usr/local/lib/databasify/horizon-workers.sh

log() {
    printf '%s [databasify] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

random_hex() {
    head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

ensure_secrets() {
    mkdir -p /app/storage/app/private /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs
    if [ ! -s "$SECRETS" ]; then
        log "generating the app key and Reverb credentials in $SECRETS"
        umask 077
        {
            printf 'APP_KEY=base64:%s\n' "$(head -c 32 /dev/urandom | base64)"
            printf 'REVERB_APP_ID=%s\n' "$(random_hex 4)"
            printf 'REVERB_APP_KEY=%s\n' "$(random_hex 16)"
            printf 'REVERB_APP_SECRET=%s\n' "$(random_hex 32)"
        } > "$SECRETS"
    fi
    while IFS='=' read -r name value; do
        eval "current=\${$name:-}"
        [ -n "$current" ] || export "$name=$value"
    done < "$SECRETS"
}

as_app() {
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
}

own_storage() {
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
        ensure_secrets
        own_storage
        prepare_database
        cache_config
        log "serving on port 80"
        serve
        ;;
    horizon)
        ensure_secrets
        tune_horizon_workers
        log "horizon workers for ${cpus} cpus and ${memory_mb} MB: default ${HORIZON_DEFAULT_MAX_PROCESSES}, monitoring ${HORIZON_MONITORING_MAX_PROCESSES}, provisioning ${HORIZON_PROVISIONING_MAX_PROCESSES}, databases ${HORIZON_DATABASES_MAX_PROCESSES}, migrations ${HORIZON_MIGRATIONS_MAX_PROCESSES}, terminals ${HORIZON_TERMINALS_MAX_PROCESSES}, estimated ceiling ${ceiling_mb} MB"
        [ "$ceiling_mb" -le "$memory_mb" ] || log "warning: busy workers may need ${ceiling_mb} MB of ${memory_mb} MB, so expect swap under load; 2 GB is recommended"
        own_storage
        cache_config
        as_app php artisan horizon
        ;;
    scheduler)
        ensure_secrets
        own_storage
        cache_config
        as_app php artisan schedule:work
        ;;
    reverb)
        ensure_secrets
        own_storage
        cache_config
        as_app php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    healthcheck)
        exec curl --fail --silent --output /dev/null http://127.0.0.1/up
        ;;
    artisan)
        ensure_secrets
        own_storage
        as_app php artisan "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
