#!/usr/bin/env bash
set -euo pipefail

SOURCE=${DATABASIFY_SOURCE:-https://raw.githubusercontent.com/${DATABASIFY_REPO:-databasifylabs/databasify}/${DATABASIFY_REF:-0.x}}
if [ -d "$SOURCE" ]; then
    # shellcheck source=scripts/lib.sh
    . "$SOURCE/scripts/lib.sh"
else
    # shellcheck source=scripts/lib.sh
    . <(curl -fsSL "$SOURCE/scripts/lib.sh")
fi

install_docker() {
    if command -v docker > /dev/null 2>&1 && docker compose version > /dev/null 2>&1; then
        return
    fi
    log "installing Docker"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker > /dev/null 2>&1 || true
}

public_address() {
    curl -fsS --max-time 5 https://api.ipify.org 2> /dev/null || hostname -I 2> /dev/null | awk '{ print $1 }'
}

check_domain() {
    local resolved
    resolved=$(getent hosts "$1" 2> /dev/null | awk '{ print $1 }' | head -n 1)
    [ "$resolved" = "$2" ] || warn "$1 resolves to ${resolved:-nothing}, not this server ($2); HTTPS starts working once it points here"
}

set_setting() {
    sed -i "s|^$1=.*|$1=$2|" "$DIR/.env"
}

setting() {
    grep "^$1=" "$DIR/.env" | cut -d= -f2-
}

require_free_ports() {
    command -v ss > /dev/null 2>&1 || return 0
    compose ps -q web | grep -q . && return 0
    for name in HTTP_PORT HTTPS_PORT; do
        local port
        port=$(setting "$name")
        [ -z "$(ss -ltnH "sport = :$port")" ] || fail "port $port is already in use; free it or change $name in $DIR/.env, then run the install script again"
    done
}

write_env() {
    if [ -f "$DIR/.env" ]; then
        log "keeping the settings in $DIR/.env"
        return
    fi
    local address domain port cloudflare
    address=$(public_address)
    address=${address:-localhost}
    domain=${DATABASIFY_DOMAIN:-$(ask "Domain for Databasify, leave empty to use the IP" "")}

    cp "$DIR/.env.example" "$DIR/.env"
    chmod 600 "$DIR/.env"
    if [ -n "$domain" ]; then
        cloudflare=${DATABASIFY_CLOUDFLARE_PROXY:-$(ask "Is $domain proxied by Cloudflare (orange cloud)? y/N" "n")}
        port=${DATABASIFY_HTTP_PORT:-80}
        set_setting APP_URL "https://$domain"
        set_setting SERVER_NAME "$domain"
        if [ "$cloudflare" = y ] || [ "$cloudflare" = Y ]; then
            set_setting CADDY_TLS "tls internal"
            log "Cloudflare serves the public certificate; set its SSL/TLS mode to Full"
        else
            check_domain "$domain" "$address"
            log "Caddy requests a Let's Encrypt certificate for $domain once it points to this server"
        fi
    else
        port=${DATABASIFY_HTTP_PORT:-$(ask "HTTP port" "80")}
        set_setting APP_URL "http://$address$([ "$port" = 80 ] || printf ':%s' "$port")"
    fi
    set_setting HTTP_PORT "$port"
    set_setting HTTPS_PORT "${DATABASIFY_HTTPS_PORT:-443}"
    log "wrote $DIR/.env (APP_URL=$(app_url))"
}

banner install
require_root
require_linux
require_memory
install_docker
download_release_files
write_env
require_free_ports
start
log "Databasify is running at $(app_url), open it to create your account"
log "update later with: curl -fsSL $SOURCE/scripts/update.sh | sudo bash"
printf '\n\033[1;33m★\033[0m Enjoying Databasify? Star it on GitHub: https://github.com/%s\n' "${DATABASIFY_REPO:-databasifylabs/databasify}"
