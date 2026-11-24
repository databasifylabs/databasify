@include('engines.postgres._psql')
@include('engines.postgres._source')
if ! version=$(on_source postgres 'SHOW server_version'); then
    output=$(jq -cn --arg error "$(tail -n 1 <<< "$SOURCE_ERROR")" '{error: $error}')
    result "$output"
    exit 0
fi
wal_level=$(on_source postgres 'SHOW wal_level' || true)
replicates=$(on_source postgres 'SELECT rolsuper OR rolreplication FROM pg_roles WHERE rolname = current_user' || true)
size=$(on_source postgres "SELECT round(COALESCE(sum(pg_database_size(datname)), 0) / 1048576) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'" || true)
listed=$(on_source postgres "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname" || true)
@if ($databases === [])
wanted=$(copyable <<< "$listed")
@else
wanted={!! $sh->arg(implode("\n", $databases)) !!}
@endif
local_databases=$(pg postgres <<< 'SELECT datname FROM pg_database')
local_extensions=$(pg postgres <<< 'SELECT name FROM pg_available_extensions')
offered={!! $sh->arg(implode("\n", $offered)) !!}
source_tables() {
    on_source "$1" "SELECT format('%I.%I.%I', current_database(), n.nspname, c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ($2) AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND NOT EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e') AND $3 ORDER BY 1" || true
}
holds_rows() {
    local probe
    grep -qxF -- "$1" <<< "$local_databases" || return 1
    pg_try "$1" <<'SQL'
SELECT string_agg(format('SELECT 1 FROM %I.%I', schemaname, tablename), ' UNION ALL ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') AND NOT EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = format('%I.%I', schemaname, tablename)::regclass AND d.deptype = 'e')
SQL
    probe=$PG_OUTPUT
    [ -z "$PG_ERROR" ] && [ -n "$probe" ] || return 1
    pg_try "$1" <<< "SELECT 1 FROM ($probe) found LIMIT 1"
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = 1 ]
}
unreadable=''
missing=''
unsupported=''
unowned=''
occupied=''
while IFS= read -r database; do
    [ -z "$database" ] && continue
    unreadable+=$(source_tables "$database" "'r', 'p', 'S'" "NOT (has_schema_privilege(n.oid, 'USAGE') AND has_table_privilege(c.oid, 'SELECT'))")$'\n'
    unowned+=$(source_tables "$database" "'r', 'p'" "NOT pg_has_role(c.relowner, 'USAGE')")$'\n'
    while IFS= read -r extension; do
        [ -z "$extension" ] && continue
        if [ "$extension" = timescaledb ]; then
            unsupported+="$extension in $database"$'\n'
            continue
        fi
        if ! grep -qxF -- "$extension" <<< "$offered" || ! grep -qxF -- "$extension" <<< "$local_extensions"; then
            missing+="$extension in $database"$'\n'
        fi
    done <<< "$(on_source "$database" "SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY 1" || true)"
    if holds_rows "$database"; then
        occupied+="$database"$'\n'
    fi
done <<< "$wanted"
output=$(jq -cn --arg version "${version%% *}" --arg wal "$wal_level" --arg replicates "$replicates" --arg size "$size" --arg databases "$listed" --arg unreadable "$unreadable" --arg unowned "$unowned" --arg occupied "$occupied" --arg missing "$missing" --arg unsupported "$unsupported" \
    'def lines: split("\n") | map(select(. != "")); {version: $version, walLevel: $wal, replicates: ($replicates == "t"), sizeMb: (($size | tonumber?) // 0), databases: ($databases | lines), unreadable: ($unreadable | lines), unowned: ($unowned | lines), occupied: ($occupied | lines), missing: ($missing | lines), unsupported: ($unsupported | lines)}')
result "$output"
