#!/usr/bin/env bash
set -euo pipefail

SOURCE=${DATABASIFY_SOURCE:-https://get.databasify.io}
if [ -d "$SOURCE" ]; then
    # shellcheck source=scripts/lib.sh
    . "$SOURCE/lib.sh"
else
    # shellcheck source=scripts/lib.sh
    . <(curl -fsSL "$SOURCE/lib.sh")
fi

docker_ready() {
    command -v docker > /dev/null 2>&1 && docker compose version > /dev/null 2>&1 < /dev/null
}

docker_version() {
    local version
    version=$(docker version --format '{{.Server.Version}}' 2> /dev/null < /dev/null | sed -n '/./{p;q;}')
    [ -z "$version" ] || { printf '%s\n' "$version"; return 0; }
    docker --version < /dev/null | sed -n 's/^Docker version \([^,]*\),.*/\1/p'
}

install_docker() {
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker > /dev/null 2>&1 || true
    step_result "Docker $(docker_version) installed"
}

public_address() {
    curl -fsS --max-time 5 https://api.ipify.org 2> /dev/null < /dev/null || hostname -I 2> /dev/null | awk '{ print $1 }'
}

domain_from() {
    local domain=$1
    domain=${domain#"${domain%%[![:space:]]*}"}
    domain=${domain%"${domain##*[![:space:]]}"}
    domain=${domain#http://}
    domain=${domain#https://}
    printf '%s' "${domain%%/*}"
}

ask_domain() {
    local answer=
    if [ -n "${DATABASIFY_DOMAIN+set}" ]; then
        domain_from "$DATABASIFY_DOMAIN"
        return 0
    fi
    has_terminal || return 0
    printf '\n  %sDomain for Databasify%s (leave empty to use %s): ' "$BOLD" "$RESET" "$1" > /dev/tty
    read -r answer < /dev/tty || true
    domain_from "$answer"
}

write_env() {
    local domain=$1 url=http://$2
    [ -z "$domain" ] || url=https://$domain
    cp "$DIR/.env.example" "$DIR/.env"
    chmod 600 "$DIR/.env"
    set_setting APP_URL "$url"
    fill_secrets
    measure_horizon_workers
    write_settings "$HORIZON_WORKERS"
    step_result "Settings written ($url)"
}

settings_step() {
    resolve_secrets
    run_step 'Writing settings' '' write_env "$1" "$2"
}

release_files_step() {
    download_release_files
    step_result "Release files in $DIR"
}

start_containers() {
    compose up -d --remove-orphans
    step_result 'Containers started'
}

docker_step() {
    if docker_ready; then
        skipped_line "Docker $(docker_version) already installed"
        return 0
    fi
    run_step 'Installing Docker' 'about a minute' install_docker
}

dns_hint() {
    printf '\n  %s%s! One DNS step left%s\n' "$YELLOW" "$BOLD" "$RESET"
    printf '    In Cloudflare, add an A record  %s%s%s → %s\n' "$BOLD" "$1" "$RESET" "$2"
    printf '    %sProxy: on (orange cloud) · SSL/TLS mode: Flexible%s\n' "$DIM" "$RESET"
}

already_installed() {
    [ -f "$DIR/.env" ] && [ -f "$DIR/compose.yaml" ]
}

stop_when_installed() {
    already_installed || return 0
    printf '\n'
    done_line "Databasify is already installed in $DIR"
    printf '\n  To update it, run:\n'
    printf '    %scurl -fsSL https://get.databasify.io/update.sh | sudo bash%s\n\n' "$DIM" "$RESET"
    exit 0
}

footer_row() {
    printf '  %s%-16s%s%s\n' "$DIM" "$1" "$2" "$RESET"
}

footer() {
    printf '\n'
    footer_row 'Installed in' "$DIR"
    footer_row 'Update' 'curl -fsSL https://get.databasify.io/update.sh | sudo bash'
    footer_row 'Uninstall' 'curl -fsSL https://get.databasify.io/uninstall.sh | sudo bash'
    footer_row 'Docs' 'https://docs.databasify.io'
    printf '\n  %s★%s Enjoying Databasify? Star it: https://github.com/%s\n\n' "$YELLOW" "$RESET" "${DATABASIFY_REPO:-databasifylabs/databasify}"
}

if [ "${1:-}" = --functions-only ]; then
    return 0
fi

LOG=$DIR/install.log
RETRY_COMMAND='curl -fsSL https://get.databasify.io/install.sh | sudo bash'
started=$(date +%s)

banner 'Welcome to the Databasify installer' "This sets up Docker and Databasify on this server. It takes 2 to 5 minutes.\n${DIM}Full log: $LOG${RESET}" "$(version_chip '' "$(release_version)")"

require_root
stop_when_installed
check_server

address=$(public_address || true)
address=${address:-localhost}
domain=$(ask_domain "$address")
[ -n "$domain" ] || printf '\n  %sUsing http://%s. Add a domain later in %s/.env%s\n' "$DIM" "$address" "$DIR" "$RESET"

mkdir -p "$DIR"
touch "$LOG"

heading Server
done_line "$SERVER_SUMMARY"
docker_step

heading Databasify
run_step 'Downloading release files' '' release_files_step
settings_step "$domain" "$address"
pull_step

heading Start
STEP_FIX="Free the port, or set HTTP_PORT in $DIR/.env" run_step 'Starting containers' '' start_containers
STEP_LOG_LINES=50 STEP_FIX='See the app container log above' run_step 'Waiting until healthy' '' wait_for_health

summary_box "Databasify is installed  in $(elapsed_since "$started")" "Open|$(app_url)" 'Next|Create your admin account on that page'
[ -z "$domain" ] || dns_hint "$domain" "$address"
footer
