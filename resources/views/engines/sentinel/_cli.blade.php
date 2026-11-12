@include('engines._runtime')
ROOT={!! $sh->arg($engine->home()) !!}
CLI={!! $sh->arg($engine->cli) !!}
FLAVOR={!! $sh->arg($engine->id) !!}

admin_password() {
    local content
    content=$(cat "$ROOT/conf/password" 2> /dev/null || true)
    content=${content#"${content%%[![:space:]]*}"}
    printf '%s' "${content%"${content##*[![:space:]]}"}"
}

cli() {
    local host=$1 port=$2 output status secret
    shift 2
    if [ -z "${CLI_SECRET+set}" ] && [ ! -f "$ROOT/conf/password" ]; then
        databasify_fail unavailable 'no password file yet'
    fi
    secret=${CLI_SECRET-$(admin_password)}
    output=$(REDISCLI_AUTH="$secret" timeout "${CLI_TIMEOUT:-30}" "$CLI" --no-auth-warning --raw -e -t 5 ${CLI_TLS:+--tls --insecure} ${CLI_USER:+--user "$CLI_USER"} -h "$host" -p "$port" "$@" 2>&1) && status=0 || status=$?
    output=${output//$'\r'/}
    if [ "$status" -eq 0 ]; then
        printf '%s' "$output"
        return 0
    fi
    case "$output" in
        *'Could not connect'* | *'Connection refused'* | *'Connection reset'* | *'Server closed the connection'* | *'No such file'*)
            databasify_fail unavailable "$output" ;;
    esac
    if [ "$status" -eq 124 ]; then
        databasify_fail unavailable "$CLI timed out after ${CLI_TIMEOUT:-30}s"
    fi
    databasify_fail internal "$output"
}

local_cli() {
    cli 127.0.0.1 6379 "$@"
}

sentinel_cli() {
    cli "${SENTINEL_HOST:-127.0.0.1}" 26379 "$@"
}

cli_try() {
    local errors
    errors=$(mktemp)
    CLI_ERROR=''
    CLI_ERROR_TEXT=''
    CLI_OUTPUT=$("$@" 3> "$errors") || {
        CLI_ERROR=$(head -n 1 "$errors" | sed 's/^DATABASIFY_ERROR //')
        CLI_ERROR_TEXT=$(tail -n +2 "$errors")
    }
    rm -f "$errors"
}

config_value() {
    local reply
    reply=$(local_cli CONFIG GET "$1"; printf x)
    reply=${reply%x}
    if [ "$(head -n 1 <<< "$reply")" != "$1" ]; then
        databasify_fail internal "$FLAVOR has no setting named $1"
    fi
    if [[ $reply != *$'\n'* ]]; then
        return 0
    fi
    reply=${reply#*$'\n'}
    printf '%s' "${reply%$'\n'}"
}

info_field() {
    sed -n "s/^$2:\(.*\)$/\1/p" <<< "$1" | head -n 1
}

restarts() {
    databasify ctl show -p NRestarts --value "$FLAVOR-server" 2> /dev/null || echo 0
}
