set -euo pipefail
@include('engines.postgres._psql')
@include('engines.postgres._source')
STATE=/srv/postgres/migration/state
OWNER={!! $sh->arg($owner) !!}
PUBLICATION={!! $sh->arg($publication) !!}
OFFERED_LIST={!! $sh->arg(implode(',', $offered)) !!}
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
    local database=$1 recovery
    recovery=$(pg postgres <<< 'SELECT pg_is_in_recovery()')
    if [ "$recovery" = t ]; then
        databasify_fail not_primary 'this node is a replica; run the command on the primary'
    fi
    pg postgres > /dev/null <<SQL
SELECT NOT EXISTS (SELECT FROM pg_database WHERE datname = $(pg_string "$database")) AS missing \gset
\if :missing
CREATE DATABASE $(pg_name "$database");
\endif
SQL
    pg "$database" > /dev/null <<SQL
SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = current_database() \gset
GRANT ALL PRIVILEGES ON DATABASE $(pg_name "$database") TO $(pg_name "$OWNER");
GRANT ALL ON SCHEMA public TO $(pg_name "$OWNER");
GRANT ALL ON ALL TABLES IN SCHEMA public TO $(pg_name "$OWNER");
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO $(pg_name "$OWNER");
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON TABLES TO $(pg_name "$OWNER");
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT ALL ON SEQUENCES TO $(pg_name "$OWNER");
SQL
    dump_and_restore "$database"
@if ($continuous)
    subscribe "$database"
@endif
}

dump_and_restore() {
    local database=$1 dump_args=(--no-owner --no-acl -Fc) dump_errors restore_errors
@if ($continuous)
    dump_args+=(--schema-only)
@endif
    dump_errors=$(mktemp)
    restore_errors=$(mktemp)
    if ! as_source "$database" timeout 259200 pg_dump "${dump_args[@]}" 2> "$dump_errors" \
        | PGOPTIONS="-c extwlist.extensions=$OFFERED_LIST" as_postgres timeout 259200 pg_restore -h /srv/postgres/run -p "$PG_PORT" -U postgres "--role=$OWNER" -d "$database" --no-owner --no-acl --exit-on-error > /dev/null 2> "$restore_errors"; then
        if [ -s "$restore_errors" ]; then
            echo "$database: pg_restore: $(tail -n 3 "$restore_errors")"
        else
            echo "$database: pg_dump: $(tail -n 3 "$dump_errors")"
        fi
        exit 1
    fi
}
@if ($continuous)

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
    if ! tables=$(on_source "$database" "SELECT string_agg(format('%I.%I', schemaname, tablename), ', ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') AND NOT EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = format('%I.%I', schemaname, tablename)::regclass AND d.deptype = 'e')"); then
        echo "$database: $SOURCE_ERROR"
        exit 1
    fi
    if [ -z "$tables" ]; then
        echo "$database has no tables to replicate"
        return 0
    fi
    on_source "$database" "DROP PUBLICATION IF EXISTS $(pg_name "$PUBLICATION"); CREATE PUBLICATION $(pg_name "$PUBLICATION") FOR TABLE $tables" > /dev/null || { echo "$database: $SOURCE_ERROR"; exit 1; }
    PG_SESSION='-c log_statement=none -c log_min_duration_statement=-1 -c log_min_error_statement=panic' pg "$database" > /dev/null <<SQL
SELECT $(pg_string "$PUBLICATION") || '_' || left($(pg_string "$database"), 20) || '_' || left(md5($(pg_string "$database")), 8) AS name \gset
CREATE SUBSCRIPTION :"name" CONNECTION $(pg_string "$(conninfo_of "$database")") PUBLICATION $(pg_name "$PUBLICATION") WITH (copy_data = true);
SQL
}
@endif

@if ($databases === [])
if ! listed=$(on_source postgres "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname"); then
    echo "$SOURCE_ERROR"
    exit 1
fi
databases=$(copyable <<< "$listed")
@else
databases={!! $sh->arg(implode("\n", $databases)) !!}
@endif
IFS=$'\n' read -r -d '' -a database_list <<< "$databases" || true
for database in "${database_list[@]}"; do
    echo "copying $database"
    copy_database "$database"
    echo "$database done"
done
