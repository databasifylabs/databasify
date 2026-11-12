exec 3>&1

databasify_fail() {
    printf 'DATABASIFY_ERROR %s\n%s\n' "$1" "$2" >&3
    printf '%s\n' "$2" >&2
    exit 1
}

phase() {
    printf 'DATABASIFY_PHASE %s\n' "$1"
}

fail_task() {
    printf '%s\n' "$1" >&2
    exit 1
}

result() {
    printf 'DATABASIFY_RESULT %s\n' "$1"
}

unit_active() {
    databasify ctl is-active --quiet "$1" 2> /dev/null
}

unit_do() {
    local output
    output=$(databasify ctl "$@" 2>&1) || databasify_fail internal "$1 ${*:2}: $output"
}

wait_until() {
    local seconds=$1 every=$2 deadline
    shift 2
    deadline=$(( $(date +%s) + seconds ))
    while true; do
        if "$@"; then
            return 0
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            return 1
        fi
        sleep "$every"
    done
}

retry_or_fail() {
    local seconds=$1 limit=$2
    shift 2
    ATTEMPT_ERROR=''
    wait_until "$seconds" 2 "$@" || fail_task "the server was not ready within $limit; last attempt: $ATTEMPT_ERROR"
}
