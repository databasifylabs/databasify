@include('engines._runtime')
PG_PORT={!! $sh->arg((string) $port) !!}

pg() {
    local database=$1 script name errors output status getenv=''
    script=$(cat)
    for name in $(env | sed -n 's/^DATABASIFY_VAR_\([a-z_][a-z0-9_]*\)=.*/\1/p' | sort); do
        getenv+="\\getenv ${name} DATABASIFY_VAR_${name}"$'\n'
    done
    errors=$(mktemp)
    output=$(printf '%s%s\n' "$getenv" "$script" | HOME=/var/lib/postgresql USER=postgres PGDATABASE="$database" PGOPTIONS='-c statement_timeout=55s' timeout 60 setpriv --reuid=64999 --regid=64999 --clear-groups psql -X -A -t -q -v ON_ERROR_STOP=1 -h /srv/postgres/run -p "$PG_PORT" -f - 2>"$errors") && status=0 || status=$?
    if [ "$status" -ne 0 ]; then
        pg_failed "$status" "$errors"
    fi
    rm -f "$errors"
    printf '%s' "$output"
}

pg_failed() {
    local message
    message=$(cat "$2")
    rm -f "$2"
    case "$1" in
        124) databasify_fail unavailable 'psql timed out after 60s' ;;
        2)
            if [[ $message =~ FATAL:\ +(database\ \".*\"\ does\ not\ exist) ]]; then
                databasify_fail not_found "${BASH_REMATCH[1]}"
            fi
            databasify_fail unavailable "$(printf '%s' "$message" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            ;;
        3) databasify_fail sql "$message" ;;
        *) databasify_fail internal "psql exited $1: $message" ;;
    esac
}

pg_try() {
    local database=$1 errors
    errors=$(mktemp)
    PG_ERROR=''
    PG_ERROR_TEXT=''
    PG_OUTPUT=$(pg "$database" 3>"$errors") || {
        PG_ERROR=$(head -n 1 "$errors" | sed 's/^DATABASIFY_ERROR //')
        PG_ERROR_TEXT=$(tail -n +2 "$errors")
    }
    rm -f "$errors"
}

clustered() {
    [ -e /srv/postgres/conf/patroni.yml ] && [ ! -e /srv/postgres/conf/postgresql.conf ]
}

patroni_state() {
    curl -fsS --max-time 5 http://127.0.0.1:8008/patroni 2> /dev/null || echo '{}'
}

patronictl_run() {
    local output
    output=$(HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 300 patronictl -c /srv/postgres/conf/patroni.yml "$@" 2>&1) \
        || databasify_fail internal "patronictl $1: $(tail -n 5 <<< "$output")"
    printf '%s' "$output"
}
