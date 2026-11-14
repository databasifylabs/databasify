set -euo pipefail
@include('engines.postgres._psql')
@include('engines.postgres._accounts')
@include('engines.postgres._source')
STATE=/srv/postgres/migration/state
set -a
. "$STATE/copy.env"
set +a
finish() {
    local status=$?
    printf '%s\n' "$([ "$status" -eq 0 ] && echo 0 || echo 1)" > "$STATE/copy.exit"
    chmod 600 "$STATE/copy.exit"
}
trap finish EXIT
exec 3>&1

copy_database() {
    local database=$1 created
    recovery=$(pg postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
)
    if [ "$recovery" = t ]; then
        databasify_fail not_primary 'this node is a replica; run the command on the primary'
    fi
    DATABASIFY_VAR_name="$database" pg postgres > /dev/null <<'SQL'
SELECT NOT EXISTS (SELECT FROM pg_database WHERE datname = :'name') AS created \gset
\if :created
CREATE DATABASE :"name";
\endif
SQL
    NAME=$MIGRATION_OWNER
    grant_write_on "$database" "$(owner_of "$database")"
    dump_and_restore "$database"
    if [ "$MIGRATION_CONTINUOUS" = true ]; then
        subscribe "$database"
    fi
}

dump_and_restore() {
    local database=$1 dump_args=(--no-owner --no-acl -Fc) dump_errors restore_errors
    if [ "$MIGRATION_CONTINUOUS" = true ]; then
        dump_args+=(--schema-only)
    fi
    dump_errors=$(mktemp)
    restore_errors=$(mktemp)
    if ! as_source "$database" timeout 259200 pg_dump "${dump_args[@]}" 2> "$dump_errors" \
        | HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 259200 pg_restore -h /srv/postgres/run -p "$PG_PORT" -U postgres "--role=$MIGRATION_OWNER" -d "$database" --no-owner --no-acl --exit-on-error > /dev/null 2> "$restore_errors"; then
        if [ -s "$restore_errors" ]; then
            echo "$database: pg_restore: $(tail -n 3 "$restore_errors")"
        else
            echo "$database: pg_dump: $(tail -n 3 "$dump_errors")"
        fi
        exit 1
    fi
}

conninfo_of() {
    local database=$1 key value parts=()
    for pair in "host=$SOURCE_HOST" "port=$SOURCE_PORT" "user=$SOURCE_USER" "password=$SOURCE_PASSWORD" "sslmode=$SOURCE_SSLMODE" "dbname=$database"; do
        key=${pair%%=*}
        value=${pair#*=}
        value=${value//\\/\\\\}
        value=${value//\'/\\\'}
        parts+=("$key='$value'")
    done
    printf '%s' "${parts[*]}"
}

subscribe() {
    local database=$1 tables
    if ! tables=$(on_source "$database" "SELECT string_agg(format('%I.%I', schemaname, tablename), ', ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')"); then
        echo "$database: $SOURCE_ERROR"
        exit 1
    fi
    if [ -z "$tables" ]; then
        echo "$database has no tables to replicate"
        return 0
    fi
    on_source "$database" "DROP PUBLICATION IF EXISTS \"$MIGRATION_PUBLICATION\"; CREATE PUBLICATION \"$MIGRATION_PUBLICATION\" FOR TABLE $tables" > /dev/null || { echo "$database: $SOURCE_ERROR"; exit 1; }
    DATABASIFY_VAR_publication="$MIGRATION_PUBLICATION" DATABASIFY_VAR_db="$database" DATABASIFY_VAR_conninfo="$(conninfo_of "$database")" pg "$database" > /dev/null <<'SQL'
SELECT :'publication' || '_' || left(:'db', 20) || '_' || left(md5(:'db'), 8) AS name \gset
CREATE SUBSCRIPTION :"name" CONNECTION :'conninfo' PUBLICATION :"publication" WITH (copy_data = true);
SQL
}

databases=$MIGRATION_DATABASES
if [ -z "$databases" ]; then
    if ! listed=$(on_source postgres "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname"); then
        echo "$SOURCE_ERROR"
        exit 1
    fi
    databases=$(copyable <<< "$listed")
fi
IFS=$'\n' read -r -d '' -a database_list <<< "$databases" || true
for database in "${database_list[@]}"; do
    echo "copying $database"
    copy_database "$database"
    echo "$database done"
done
