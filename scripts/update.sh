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

add_new_settings() {
    while IFS= read -r line; do
        case "$line" in '' | '#'*) continue ;; esac
        name=${line%%=*}
        grep -q "^${name}=" "$DIR/.env" && continue
        printf '%s\n' "$line" >> "$DIR/.env"
        log "added the new setting $name"
    done < "$DIR/.env.example"
}

banner update
require_root
require_installed
download_release_files
add_new_settings
start
docker image prune -f --filter "label=org.opencontainers.image.title=databasify" > /dev/null
log "Databasify is up to date at $(app_url)"
