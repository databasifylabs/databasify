#!/usr/bin/env bash

DIR=${DATABASIFY_DIR:-/opt/databasify}
PULL=${DATABASIFY_PULL:-true}
SECRET_NAMES='APP_KEY REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET'

SUPPORTED_SYSTEMS="Databasify runs on Ubuntu 24.04 or 26.04, Debian 12 or 13,
  Rocky Linux 9 or AlmaLinux 9."

refuse_check() {
    [ -z "${SERVER_CHECKS:-}" ] || heading Server
    refuse "$1" "${2:-}"
}

require_root() {
    [ "$(id -u)" -eq 0 ] || refuse_check "Run it as root: ${RETRY_COMMAND:-sudo bash}" ""
}

require_linux() {
    [ "$(uname -s)" = Linux ] || refuse_check "Databasify installs on Linux servers" "$SUPPORTED_SYSTEMS"
}

supported_os_name() {
    local fields id version name
    # shellcheck source=/dev/null
    fields=$(. "$1" && printf '%s\n%s\n%s\n' "${ID:-}" "${VERSION_ID:-}" "${NAME:-}")
    {
        IFS= read -r id
        IFS= read -r version
        IFS= read -r name
    } <<< "$fields"
    name=${name% GNU/Linux}
    printf '%s%s\n' "${name:-This system}" "${version:+ $version}"
    case "$id:$version" in
        ubuntu:24.04 | ubuntu:26.04 | debian:12 | debian:13 | rocky:9 | rocky:9.* | almalinux:9 | almalinux:9.*) return 0 ;;
    esac
    return 1
}

require_supported_os() {
    OS_NAME=$(supported_os_name /etc/os-release 2> /dev/null) && return 0
    refuse_check "$OS_NAME is not supported" "$SUPPORTED_SYSTEMS"
}

memory_mb() {
    awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo
}

require_memory() {
    local memory gigabytes
    memory=$(memory_mb)
    [ "$memory" -ge 900 ] || refuse_check "Databasify needs at least 1 GB of memory, this server has ${memory} MB" ""
    gigabytes=$(((memory + 512) / 1024))
    MEMORY_SUMMARY="$((gigabytes > 1 ? gigabytes : 1)) GB memory"
    [ "$memory" -ge 1800 ] || MEMORY_SUMMARY="$MEMORY_SUMMARY (2 GB recommended)"
}

env_value() {
    grep "^$1=" "$DIR/.env" 2> /dev/null | cut -d= -f2- | tr -d '\r"'"'"'' || true
}

app_container_running() {
    [ -f "$DIR/compose.yaml" ] && [ -n "$(compose ps -q app 2> /dev/null)" ]
}

require_free_port() {
    PORT_SUMMARY=
    command -v ss > /dev/null 2>&1 || return 0
    app_container_running && return 0
    local port
    port=$(env_value HTTP_PORT)
    port=${port:-80}
    [ -z "$(ss -ltnH "sport = :$port")" ] || refuse_check "Port $port is already in use" "Free it or set HTTP_PORT in $DIR/.env, then run the install command again."
    PORT_SUMMARY=", port $port free"
}

check_server() {
    SERVER_CHECKS=1
    require_linux
    require_supported_os
    require_memory
    require_free_port
    SERVER_CHECKS=
    SERVER_SUMMARY="$OS_NAME, $MEMORY_SUMMARY$PORT_SUMMARY"
}

require_installed() {
    [ -f "$DIR/compose.yaml" ] || refuse_check "Databasify is not installed in $DIR" "Run the install command to set it up."
}

fetch() {
    if [ -d "$SOURCE" ]; then
        cp "$SOURCE/$1" "$2"
    else
        curl -fsSL "$SOURCE/$1" -o "$2" < /dev/null
    fi
}

pinned_version() {
    env_value DATABASIFY_VERSION
}

published_version() {
    local file
    file=$(mktemp)
    fetch version "$file" 2> /dev/null || true
    tr -d '[:space:]' < "$file"
    rm -f "$file"
}

release_version() {
    local pinned
    pinned=$(pinned_version)
    if [ -n "$pinned" ] && [ "$pinned" != latest ]; then
        printf '%s\n' "$pinned"
        return 0
    fi
    published_version
}

running_version() {
    local container
    container=$(compose ps -q app 2> /dev/null) || return 0
    [ -n "$container" ] || return 0
    docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.version" }}' "$container" < /dev/null 2> /dev/null || true
}

version_chip() {
    local from=$1 to=$2
    [ -n "$to" ] || return 0
    if [ -z "$from" ] || [ "$from" = "$to" ]; then
        printf ' v%s ' "$to"
        return 0
    fi
    printf ' v%s → v%s ' "$from" "$to"
}

compose() {
    (cd "$DIR" && docker compose "$@" < /dev/null)
}

download_release_files() {
    mkdir -p "$DIR"
    fetch compose.yaml "$DIR/compose.yaml"
    fetch env.example "$DIR/.env.example"
}

wait_until_healthy() {
    local container
    for _ in $(seq 1 60); do
        container=$(compose ps -q app)
        if [ -n "$container" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$container")" = healthy ]; then
            return 0
        fi
        sleep 5
    done
    compose logs --tail 50 app >> "${LOG:-/dev/null}" 2>&1
    return 1
}

pull_images() {
    compose pull --quiet
    step_result 'Images pulled'
}

pull_step() {
    if [ "$PULL" != true ]; then
        skipped_line 'Images (DATABASIFY_PULL=false)'
        return 0
    fi
    run_step 'Pulling images' 'this can take a few minutes' pull_images
}

wait_for_health() {
    wait_until_healthy
    step_result 'Databasify is healthy'
}

app_url() {
    env_value APP_URL
}

set_setting() {
    local file
    file=$(mktemp "$DIR/.env.XXXXXX")
    SETTING_NAME=$1 SETTING_VALUE=$2 awk '
        index($0, ENVIRON["SETTING_NAME"] "=") == 1 { print ENVIRON["SETTING_NAME"] "=" ENVIRON["SETTING_VALUE"]; found = 1; next }
        { print }
        END { if (!found) print ENVIRON["SETTING_NAME"] "=" ENVIRON["SETTING_VALUE"] }
    ' "$DIR/.env" > "$file"
    mv "$file" "$DIR/.env"
}

write_settings() {
    local line
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        set_setting "${line%%=*}" "${line#*=}"
    done <<< "$1"
}

value_in() {
    grep "^$2=" <<< "$1" | head -n 1 | cut -d= -f2- || true
}

random_hex() {
    head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

new_secrets() {
    printf 'APP_KEY=base64:%s\n' "$(head -c 32 /dev/urandom | base64)"
    printf 'REVERB_APP_ID=%s\n' "$(random_hex 4)"
    printf 'REVERB_APP_KEY=%s\n' "$(random_hex 16)"
    printf 'REVERB_APP_SECRET=%s\n' "$(random_hex 32)"
}

missing_secrets() {
    local name
    for name in $SECRET_NAMES; do
        [ -n "$(env_value "$name")" ] || printf '%s\n' "$name"
    done
}

storage_volume_missing() {
    docker info > /dev/null 2>&1 < /dev/null || return 1
    ! docker volume inspect databasify_storage > /dev/null 2>&1 < /dev/null
}

resolve_secrets() {
    SECRETS=$(new_secrets)
    [ -z "$(env_value APP_KEY)" ] || return 0
    storage_volume_missing && return 0
    step_failed "APP_KEY is missing from $DIR/.env" "Put APP_KEY and the REVERB_APP_* values from your backup into $DIR/.env, then run the command again. No new key is generated while the databasify_storage volume exists or Docker does not answer, because it would make that data unreadable."
}

fill_secrets() {
    local name
    for name in $(missing_secrets); do
        set_setting "$name" "$(value_in "$SECRETS" "$name")"
    done
    step_result "App key and Reverb credentials generated in $DIR/.env"
}

secrets_step() {
    [ -n "$(missing_secrets)" ] || return 0
    resolve_secrets
    run_step 'Saving the app key and Reverb credentials' '' fill_secrets
}

load_horizon_workers() {
    local file
    file=$(mktemp)
    if ! fetch horizon-workers.sh "$file" 2>> "${LOG:-/dev/null}"; then
        rm -f "$file"
        step_failed 'Downloading the Horizon sizing failed'
    fi
    # shellcheck source=scripts/horizon-workers.sh
    . "$file"
    rm -f "$file"
}

sizing_overrides_in_env() {
    grep -oE '^(DATABASIFY_(CPUS|MEMORY_MB|RESERVE_MB|STACK_MB|PHP_PROCESS_MB|WORKER_RSS_MB)|HORIZON_[A-Z]+_MIN_PROCESSES)=' "$DIR/.env" 2> /dev/null | tr -d = || true
}

use_sizing_overrides() {
    local name
    for name in $(sizing_overrides_in_env); do
        [ -n "${!name:-}" ] || export "$name=$(env_value "$name")"
    done
}

measure_horizon_workers() {
    load_horizon_workers
    use_sizing_overrides
    SERVER_CPUS=$(cpu_count)
    SERVER_MEMORY_MB=$(memory_size_mb)
    HORIZON_WORKERS=$(horizon_workers "$SERVER_CPUS" "$SERVER_MEMORY_MB")
}

server_size() {
    printf '%s CPU%s and %s MB' "$SERVER_CPUS" "$([ "$SERVER_CPUS" -eq 1 ] || printf s)" "$SERVER_MEMORY_MB"
}

empty_horizon_workers() {
    local line
    while IFS= read -r line; do
        [ -n "$(env_value "${line%%=*}")" ] || printf '%s\n' "$line"
    done <<< "$HORIZON_WORKERS"
}

changed_horizon_workers() {
    local line current
    while IFS= read -r line; do
        current=$(env_value "${line%%=*}")
        [ -z "$current" ] || [ "$current" = "${line#*=}" ] || printf '%s\n' "$line"
    done <<< "$HORIZON_WORKERS"
}

horizon_group_of() {
    local group=${1#HORIZON_}
    printf '%s' "${group%_MAX_PROCESSES}" | tr '[:upper:]' '[:lower:]'
}

horizon_changes_in() {
    local line name summary=
    while IFS= read -r line; do
        name=${line%%=*}
        summary="${summary:+$summary, }$(horizon_group_of "$name") $(env_value "$name") → ${line#*=}"
    done <<< "$1"
    printf '%s' "$summary"
}

horizon_changes_accepted() {
    [ "${DATABASIFY_YES:-false}" = true ] && return 0
    has_terminal || return 1
    local answer=
    printf '\n  %sHorizon workers for %s: %s. Apply? [y/N]: %s' "$RED" "$(server_size)" "$(horizon_changes_in "$1")" "$RESET" > /dev/tty
    read -r answer < /dev/tty || true
    [ "$answer" = y ] || [ "$answer" = Y ]
}

horizon_step() {
    local empty changed
    measure_horizon_workers
    empty=$(empty_horizon_workers)
    changed=$(changed_horizon_workers)
    [ -n "$empty$changed" ] || return 0
    write_settings "$empty"
    if [ -n "$changed" ] && ! horizon_changes_accepted "$changed"; then
        skipped_line "Horizon workers kept (run again to apply: $(horizon_changes_in "$changed"))" ""
        return 0
    fi
    write_settings "$changed"
    done_line "Horizon workers updated for $(server_size)"
}

use_color() {
    if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
        RESET=$'\033[0m'
        BOLD=$'\033[1m'
        DIM=$'\033[2m'
        RED=$'\033[31m'
        GREEN=$'\033[32m'
        YELLOW=$'\033[33m'
        return
    fi
    RESET='' BOLD='' DIM='' RED='' GREEN='' YELLOW=''
}

use_color

BANNER_FADE=("54;63;249" "79;99;250" "104;135;251" "129;171;252" "154;207;254" "176;224;255")
SPINNER_FRAMES=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
STEP_RESULT_FILE=

rgb_foreground() {
    [ -n "$RESET" ] || return 0
    printf '\033[38;2;%sm' "$1"
}

chip_background() {
    [ -z "$RESET" ] || printf '\033[37;48;2;%sm' "$1"
}

banner() {
    local title=$1 description=${2:-} chip=${3:-} chip_colour=${4:-54;63;249}
    local line index=0 chip_start=

    while IFS= read -r line; do
        chip_start=
        if [ "$index" -eq 5 ] && [ -n "$chip" ]; then
            chip_start="  ${BOLD}$(chip_background "$chip_colour")${chip}${RESET}"
        fi
        printf '\n  %s%s%s%s' "$(rgb_foreground "${BANNER_FADE[$index]}")" "$line" "$RESET" "$chip_start"
        index=$((index + 1))
    done <<'ART'
 ____        _        _               _  __
|  _ \  __ _| |_ __ _| |__   __ _ ___(_)/ _|_   _
| | | |/ _` | __/ _` | '_ \ / _` / __| | |_| | | |
| |_| | (_| | || (_| | |_) | (_| \__ \ |  _| |_| |
|____/ \__,_|\__\__,_|_.__/ \__,_|___/_|_|  \__, |
                                            |___/
ART
    printf '\n\n  %s%s%s\n' "$BOLD" "$title" "$RESET"
    [ -z "$description" ] || printf '%b\n' "$description" | sed 's/^/  /'
}

heading() {
    printf '\n  %s%s%s\n' "$BOLD" "$1" "$RESET"
}

done_line() {
    printf '    %s✓%s %s\n' "$GREEN" "$RESET" "$1"
}

skipped_line() {
    printf '    %s– %s%s%s\n' "$DIM" "$1" "${2-" (skipped)"}" "$RESET"
}

step_result() {
    [ -n "$STEP_RESULT_FILE" ] || return 0
    printf '%s\n' "$*" >> "$STEP_RESULT_FILE"
}

log_file_readable() {
    [ -n "${LOG:-}" ] && [ "$LOG" != /dev/null ] && [ -f "$LOG" ]
}

step_failed() {
    printf '    %s✗%s %s\n' "$RED" "$RESET" "$1"
    printf '\n'
    if log_file_readable; then
        tail -n "${STEP_LOG_LINES:-20}" "$LOG" | sed 's/^/    /' | while IFS= read -r line; do
            printf '%s%s%s\n' "$DIM" "$line" "$RESET"
        done
        printf '\n'
    fi
    [ -z "${2:-}" ] || printf '    %sFix it%s   %s\n' "$BOLD" "$RESET" "$2"
    [ -z "${RETRY_COMMAND:-}" ] || printf '    %sRetry%s    %s\n' "$BOLD" "$RESET" "$RETRY_COMMAND"
    ! log_file_readable || printf '    %sFull log %s%s\n' "$DIM" "$LOG" "$RESET"
    exit 1
}

refuse() {
    printf '    %s✗%s %s\n\n' "$RED" "$RESET" "$1"
    printf '  %sNothing was changed on this server.%s\n\n' "$RED" "$RESET"
    printf '  %s\n' "$2"
    exit 1
}

spin_until_exit() {
    local pid=$1 label=$2 index=0 count=${#SPINNER_FRAMES[@]}
    while kill -0 "$pid" 2> /dev/null; do
        printf '\r\033[K    %s%s%s %s' "$YELLOW" "${SPINNER_FRAMES[$((index % count))]}" "$RESET" "$label"
        index=$((index + 1))
        sleep 0.1
    done
    printf '\r\033[K'
}

kill_tree() {
    local child
    for child in $(pgrep -P "$1" 2> /dev/null || true); do
        kill_tree "$child"
    done
    kill "$1" 2> /dev/null || true
}

abort_step() {
    kill_tree "$1"
    [ ! -t 1 ] || printf '\r\033[K\n'
    rm -f "$STEP_RESULT_FILE"
    exit 130
}

run_step() {
    local running_label=$1 hint=$2 label status=0 pid
    shift 2
    label=$running_label
    [ -z "$hint" ] || label="$running_label ${DIM}($hint)${RESET}"

    STEP_RESULT_FILE=$(mktemp)
    "$@" >> "${LOG:-/dev/null}" 2>&1 &
    pid=$!
    trap 'abort_step "$pid"' INT TERM
    [ ! -t 1 ] || spin_until_exit "$pid" "$label"
    wait "$pid" || status=$?
    trap - INT TERM

    local done_label
    done_label=$(tail -n 1 "$STEP_RESULT_FILE")
    rm -f "$STEP_RESULT_FILE"

    [ "$status" -eq 0 ] || step_failed "$running_label failed" "${STEP_FIX:-}"
    done_line "${done_label:-$running_label}"
}

display_width() {
    printf '%s' "$1" | LC_ALL=C tr -d '\200-\277' | wc -c | tr -d ' '
}

summary_box() {
    local title="✓ $1" row label width inner_width rule
    shift

    inner_width=$(display_width "$title")
    for row in "$@"; do
        width=$(display_width "$(printf '%-12s%s' "${row%%|*}" "${row#*|}")")
        [ "$width" -le "$inner_width" ] || inner_width=$width
    done
    inner_width=$((inner_width > 12 ? inner_width : 12))
    printf -v rule '%*s' $((inner_width + 4)) ''
    rule=${rule// /─}

    printf '\n  %s╭%s╮\n' "$GREEN" "$rule"
    box_line "$title" "$inner_width" "$BOLD"
    box_line "" "$inner_width"
    for row in "$@"; do
        label=${row%%|*}
        box_line "$(printf '%-12s%s' "$label" "${row#*|}")" "$inner_width" "" "$label"
    done
    printf '  %s╰%s╯%s\n' "$GREEN" "$rule" "$RESET"
}

box_line() {
    local text=$1 inner_width=$2 emphasis=${3:-} bold_prefix=${4:-} padding
    printf -v padding '%*s' $((inner_width - $(display_width "$text"))) ''
    if [ -n "$bold_prefix" ]; then
        text="${BOLD}${bold_prefix}${RESET}${text#"$bold_prefix"}"
    fi
    printf '  %s│%s  %s%s%s%s  %s│%s\n' "$GREEN" "$RESET" "$emphasis" "$text" "$RESET" "$padding" "$GREEN" "$RESET"
}

elapsed_since() {
    local seconds=$(($(date +%s) - $1))
    if [ "$seconds" -lt 60 ]; then
        printf '%ds\n' "$seconds"
        return
    fi
    printf '%dm %ds\n' $((seconds / 60)) $((seconds % 60))
}

has_terminal() {
    { : < /dev/tty; } 2> /dev/null
}
