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

confirm() {
    [ "${DATABASIFY_YES:-false}" = true ] && return 0
    has_terminal || fail "$1 Rerun with DATABASIFY_YES=true to confirm."
    printf '%s [y/N] ' "$1" > /dev/tty
    answer=
    read -r answer < /dev/tty || true
    [ "$answer" = y ] || [ "$answer" = Y ]
}

banner uninstall
require_root
require_installed

if [ "${1:-}" = --purge ]; then
    confirm "Delete Databasify and all its data (servers, databases list, keys)?" || fail "nothing removed"
    compose down --volumes --remove-orphans
    rm -rf "$DIR"
    log "Databasify and its data are removed"
else
    compose down --remove-orphans
    log "Databasify is stopped; its data stays in $DIR and its Docker volumes"
    log "start it again with the install script, or remove the data with: uninstall.sh --purge"
fi
log "the databases on your servers keep running; Databasify no longer manages them"
