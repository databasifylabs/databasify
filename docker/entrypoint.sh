#!/bin/sh
set -eu

cd /app

SECRETS=/app/storage/databasify.env

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

prepare_database() {
    if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
        touch "${DB_DATABASE:-/app/storage/database.sqlite}"
    fi
    php artisan migrate --force --no-interaction
}

cache_config() {
    php artisan optimize --no-interaction > /dev/null
}

own_storage() {
    chown -R www-data:www-data /app/storage /app/bootstrap/cache
}

role=${1:-web}
[ "$#" -gt 0 ] && shift

case "$role" in
    web)
        ensure_secrets
        prepare_database
        cache_config
        own_storage
        log "serving on ${SERVER_NAME}"
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    horizon)
        ensure_secrets
        cache_config
        exec php artisan horizon
        ;;
    scheduler)
        ensure_secrets
        cache_config
        exec php artisan schedule:work
        ;;
    reverb)
        ensure_secrets
        cache_config
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    healthcheck)
        exec curl --fail --silent --output /dev/null http://127.0.0.1:8081/up
        ;;
    artisan)
        ensure_secrets
        exec php artisan "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
