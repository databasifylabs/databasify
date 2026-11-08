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

missing_settings() {
    local line name
    while IFS= read -r line; do
        case "$line" in '' | '#'*) continue ;; esac
        name=${line%%=*}
        grep -q "^${name}=" "$DIR/.env" || printf '%s\n' "$line"
    done < "$DIR/.env.example" | awk -F= '!seen[$1]++'
}

add_new_settings() {
    local line names='' count=0
    while IFS= read -r line; do
        set_setting "${line%%=*}" "${line#*=}"
        names="${names:+$names, }${line%%=*}"
        count=$((count + 1))
    done < <(missing_settings)
    [ "$count" -gt 0 ] || return 0
    step_result "$count new setting$([ "$count" -eq 1 ] || printf s) added ($names)"
}

RETIRED_HOSTS='REDIS_HOST=valkey REVERB_HOST=reverb'

settings_on_retired_hosts() {
    local pair
    for pair in $RETIRED_HOSTS; do
        if [ "$(env_value "${pair%%=*}")" = "${pair#*=}" ]; then
            printf '%s\n' "${pair%%=*}"
        fi
    done
}

use_bundled_services() {
    local name
    for name in $(settings_on_retired_hosts); do
        set_setting "$name" 127.0.0.1
    done
    step_result 'Redis and Reverb now run inside the app container'
}

bundled_services_step() {
    [ -n "$(settings_on_retired_hosts)" ] || return 0
    run_step 'Moving Redis and Reverb into the app container' '' use_bundled_services
}

release_files_step() {
    download_release_files
    step_result 'Release files refreshed'
}

settings_step() {
    [ -n "$(missing_settings)" ] || return 0
    run_step 'Adding new settings' '' add_new_settings
}

restart_containers() {
    compose up -d --remove-orphans
    step_result 'Containers restarted'
}

remove_old_images() {
    docker image prune -f --filter "label=org.opencontainers.image.title=databasify"
    step_result 'Old images removed'
}

remove_valkey_volume() {
    docker volume rm databasify_valkey
    step_result 'Valkey volume removed'
}

valkey_volume_step() {
    docker volume inspect databasify_valkey > /dev/null 2>&1 < /dev/null || return 0
    run_step 'Removing the old Valkey volume' '' remove_valkey_volume
}

if [ "${1:-}" = --functions-only ]; then
    return 0
fi

LOG=$DIR/update.log
RETRY_COMMAND='curl -fsSL https://get.databasify.io/update.sh | sudo bash'
started=$(date +%s)

require_root
require_installed
touch "$LOG"

banner 'Welcome to the Databasify updater' "This pulls the latest release and restarts Databasify. Your data and settings stay.\n${DIM}Full log: $LOG${RESET}" "$(version_chip "$(running_version)" "$(release_version)")"

heading Databasify
run_step 'Refreshing release files' '' release_files_step
settings_step
bundled_services_step
secrets_step
horizon_step
pull_step

heading Start
run_step 'Restarting containers' '' restart_containers
STEP_LOG_LINES=50 STEP_FIX='See the app container log above' run_step 'Waiting until healthy' '' wait_for_health
run_step 'Removing old images' '' remove_old_images
valkey_volume_step

summary_box "Databasify is up to date  in $(elapsed_since "$started")" "Open|$(app_url)"
