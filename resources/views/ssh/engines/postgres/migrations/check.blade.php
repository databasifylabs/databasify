@include('ssh.engines.postgres.migrations.env')
source_query_in() { in_pg sh -c 'psql "host=$SOURCE_HOST port=$SOURCE_PORT user=$SOURCE_USER dbname=$1 sslmode=$SOURCE_SSLMODE connect_timeout=10" -XAtc "$2" 2>&1' sh "$1" "$2"; }
source_query() { source_query_in postgres "$1"; }
source_tables_in() { tables=$(source_query_in "$1" "SELECT string_agg(format('%I.%I.%I', current_database(), n.nspname, c.relname), ' ' ORDER BY n.nspname, c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ($2) AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND $3") || return 0; printf '%s' "$tables"; }
if ! version=$(source_query "SHOW server_version"); then
    echo "reachable=no"
    printf 'error=%s\n' "$(printf '%s' "$version" | tr '\n' ' ')"
    exit 0
fi
echo "reachable=yes"
printf 'version=%s\n' "$(printf '%s' "$version" | cut -d ' ' -f 1)"
printf 'wal_level=%s\n' "$(source_query "SHOW wal_level")"
printf 'can_replicate=%s\n' "$(source_query "SELECT rolsuper OR rolreplication FROM pg_roles WHERE rolname = current_user")"
printf 'size_mb=%s\n' "$(source_query "SELECT round(COALESCE(sum(pg_database_size(datname)), 0) / 1048576) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'")"
printf 'databases=%s\n' "$(source_query "SELECT string_agg(datname, ' ' ORDER BY datname) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'")"
wanted='{{ $databases }}'
[ -n "$wanted" ] || wanted=$(source_query "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname" | { grep -Ex '{!! $schemaName !!}' || true; } | paste -sd ' ' -)
unreadable=""
unowned=""
for db in $wanted; do
    unreadable="$unreadable $(source_tables_in "$db" "'r', 'p', 'S'" "NOT (has_schema_privilege(n.oid, 'USAGE') AND has_table_privilege(c.oid, 'SELECT'))")"
    unowned="$unowned $(source_tables_in "$db" "'r', 'p'" "NOT pg_has_role(c.relowner, 'USAGE')")"
done
printf 'unreadable=%s\n' "${unreadable# }"
printf 'unowned=%s\n' "${unowned# }"
existing=$(in_pg psql -X -U postgres -d postgres -Atc "SELECT datname FROM pg_database" || true)
occupied=""
for db in $wanted; do
    printf '%s\n' "$existing" | grep -Fxq "$db" || continue
    probe=$(in_pg psql -X -U postgres -d "$db" -Atc "SELECT string_agg(format('SELECT 1 FROM %I.%I', schemaname, tablename), ' UNION ALL ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')" || true)
    [ -n "$probe" ] || continue
    [ "$(in_pg psql -X -U postgres -d "$db" -Atc "SELECT 1 FROM ($probe) found LIMIT 1" || true)" != "1" ] || occupied="$occupied $db"
done
printf 'occupied=%s\n' "${occupied# }"
