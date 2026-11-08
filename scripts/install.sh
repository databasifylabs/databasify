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

set_setting() {
    sed -i "s|^$1=.*|$1=$2|" "$DIR/.env"
}

setting() {
    grep "^$1=" "$DIR/.env" | cut -d= -f2-
}

require_free_ports() {
    command -v ss > /dev/null 2>&1 || return 0
    compose ps -q web | grep -q . && return 0
    local port
    port=$(setting HTTP_PORT || true)
    port=${port:-80}
    [ -z "$(ss -ltnH "sport = :$port")" ] || fail "port $port is already in use; free it or set HTTP_PORT in $DIR/.env, then run the install script again"
}

write_env() {
    if [ -f "$DIR/.env" ]; then
        log "keeping the settings in $DIR/.env"
        return
    fi
    local address domain
    address=$(public_address)
    address=${address:-localhost}
    domain=${DATABASIFY_DOMAIN:-$(ask "Domain for Databasify, leave empty to use the IP" "")}

    cp "$DIR/.env.example" "$DIR/.env"
    chmod 600 "$DIR/.env"
    if [ -n "$domain" ]; then
        set_setting APP_URL "https://$domain"
        log "proxy $domain through Cloudflare (orange cloud) to $address with SSL/TLS mode Flexible; Cloudflare serves HTTPS"
    else
        set_setting APP_URL "http://$address"
    fi
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
