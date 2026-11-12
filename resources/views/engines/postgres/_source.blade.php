as_source() {
    local database=$1
    shift
    PGHOST="$SOURCE_HOST" PGPORT="$SOURCE_PORT" PGUSER="$SOURCE_USER" PGPASSWORD="$SOURCE_PASSWORD" PGSSLMODE="$SOURCE_SSLMODE" PGDATABASE="$database" PGCONNECT_TIMEOUT=10 \
        HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups "$@"
}

on_source() {
    local database=$1 query=$2 output
    output=$(as_source "$database" timeout 30 psql -X -A -t -c "$query" 2>&1) || {
        SOURCE_ERROR="psql -X: exit status $?: $(tail -n 5 <<< "$output" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        return 1
    }
    printf '%s' "$(sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' <<< "$output")"
}

on_conninfo() {
    local conninfo=$1 query=$2
    [ -z "$conninfo" ] && return 1
    HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 30 psql "$conninfo connect_timeout=10" -X -A -t -c "$query" 2> /dev/null
}

copyable() {
    grep -E '^[A-Za-z][A-Za-z0-9_]*$' || true
}
