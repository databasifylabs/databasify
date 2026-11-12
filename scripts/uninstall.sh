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

PURGE_QUESTION='Delete Databasify and all its data (servers, databases list, keys)?'

confirm_purge() {
    [ "${DATABASIFY_YES:-false}" = true ] && return 0
    has_terminal || refuse 'Confirmation needed' 'Rerun with DATABASIFY_YES=true to confirm.'
    local answer=
    printf '\n  %s%s [y/N]: %s' "$RED" "$PURGE_QUESTION" "$RESET" > /dev/tty
    read -r answer < /dev/tty || true
    [ "$answer" = y ] || [ "$answer" = Y ] || refuse 'Nothing removed' 'Run the command again whenever you want to remove it.'
}

stop_containers() {
    compose down --remove-orphans
    step_result 'Containers stopped'
}

delete_volumes() {
    compose down --volumes --remove-orphans
    step_result 'Data volumes deleted'
}

remove_directory() {
    rm -rf "$DIR"
    step_result "$DIR removed"
}

require_removable_directory() {
    local path=$DIR
    while [ "${path%/}" != "$path" ]; do
        path=${path%/}
    done
    case "/$path/" in
        */./* | */../*) ;;
        *)
            case "$path" in
                *//*) ;;
                /*/*) return 0 ;;
            esac
            ;;
    esac
    refuse "Refusing to remove '$DIR'" 'Set DATABASIFY_DIR to the Databasify folder, for example /opt/databasify.'
}

purge() {
    require_removable_directory
    confirm_purge
    heading Databasify
    run_step 'Stopping containers' '' stop_containers
    run_step 'Deleting data volumes' '' delete_volumes
    run_step 'Removing the updater' '' remove_updater
    run_step "Removing $DIR" '' remove_directory
    printf '\n  %s✓ Databasify is removed.%s %sDocker stays installed.%s\n' "$GREEN" "$RESET" "$DIM" "$RESET"
}

stop_only() {
    heading Databasify
    run_step 'Stopping containers' '' stop_containers
    printf '\n  Databasify is stopped. Its data stays in %s and its Docker volumes.\n' "$DIR"
    printf '  %sStart it again with the install command, or remove everything with:\n  curl -fsSL https://get.databasify.io/uninstall.sh | sudo bash -s -- --purge%s\n' "$DIM" "$RESET"
}

if [ "${1:-}" = --functions-only ]; then
    return 0
fi

RETRY_COMMAND='curl -fsSL https://get.databasify.io/uninstall.sh | sudo bash'
[ "${1:-}" != --purge ] || RETRY_COMMAND="$RETRY_COMMAND -s -- --purge"

require_root
require_installed

LOG=$(mktemp)
banner 'Welcome to the Databasify uninstaller' 'This removes Databasify from this server. The databases on your\nservers keep running; Databasify just stops managing them.' "$(version_chip '' "$(running_version)")" '248;81;73'

if [ "${1:-}" = --purge ]; then
    purge
else
    stop_only
fi
rm -f "$LOG"
