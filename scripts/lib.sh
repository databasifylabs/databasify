#!/usr/bin/env bash

DIR=${DATABASIFY_DIR:-/opt/databasify}
PULL=${DATABASIFY_PULL:-true}

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarning:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

require_root() {
    [ "$(id -u)" -eq 0 ] || fail "run as root, for example: curl -fsSL ... | sudo bash"
}

require_linux() {
    [ "$(uname -s)" = Linux ] || fail "Databasify installs on Linux servers"
}

require_installed() {
    [ -f "$DIR/compose.yaml" ] || fail "Databasify is not installed in $DIR"
}

fetch() {
    if [ -d "$SOURCE" ]; then
        cp "$SOURCE/$1" "$2"
    else
        curl -fsSL "$SOURCE/$1" -o "$2"
    fi
}

compose() {
    (cd "$DIR" && docker compose "$@")
}

download_release_files() {
    log "downloading the Databasify release files to $DIR"
    mkdir -p "$DIR/scripts"
    fetch docker/compose.yaml "$DIR/compose.yaml"
    fetch docker/.env.example "$DIR/.env.example"
    for script in lib install update uninstall; do
        fetch "scripts/$script.sh" "$DIR/scripts/$script.sh"
    done
    chmod 755 "$DIR"/scripts/*.sh
}

wait_until_healthy() {
    log "waiting for Databasify to start"
    for _ in $(seq 1 60); do
        container=$(compose ps -q web)
        if [ -n "$container" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$container")" = healthy ]; then
            return
        fi
        sleep 5
    done
    compose logs --tail 50 web >&2
    fail "Databasify did not become healthy, see the logs above"
}

start() {
    if [ "$PULL" = true ]; then
        log "pulling the images"
        compose pull --quiet
    fi
    compose up -d --remove-orphans
    wait_until_healthy
}

app_url() {
    grep '^APP_URL=' "$DIR/.env" | cut -d= -f2-
}

banner() {
    printf '\033[1;34m'
    cat <<'BANNER'
 ____        _        _               _  __
|  _ \  __ _| |_ __ _| |__   __ _ ___(_)/ _|_   _
| | | |/ _` | __/ _` | '_ \ / _` / __| | |_| | | |
| |_| | (_| | || (_| | |_) | (_| \__ \ |  _| |_| |
|____/ \__,_|\__\__,_|_.__/ \__,_|___/_|_|  \__, |
                                            |___/
BANNER
    printf '\033[0m  Open-source, self-hosted database clusters \033[2m· %s\033[0m\n\n' "$1"
}

has_terminal() {
    { : < /dev/tty; } 2> /dev/null
}

ask() {
    local answer=
    if has_terminal; then
        printf '\033[1m%s\033[0m [%s]: ' "$1" "$2" > /dev/tty
        read -r answer < /dev/tty || true
    fi
    printf '%s' "${answer:-$2}"
}
