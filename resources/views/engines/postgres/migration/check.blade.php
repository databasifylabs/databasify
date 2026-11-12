@include('engines.postgres._psql')
@include('engines.postgres._source')
list_query="SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname"
if ! version=$(on_source postgres 'SHOW server_version'); then
    message=$(tail -n 1 <<< "$SOURCE_ERROR")
    result "$(jq -cn --arg error "$message" '{reachable: false, error: $error, version: null, walLevel: null, canReplicate: false, sizeMb: null, databases: [], unreadable: [], unowned: [], occupied: [], warnings: [], problems: []}')"
    exit 0
fi
wal_level=$(on_source postgres 'SHOW wal_level' || true)
replicates=$(on_source postgres 'SELECT rolsuper OR rolreplication FROM pg_roles WHERE rolname = current_user' || true)
size=$(on_source postgres "SELECT round(COALESCE(sum(pg_database_size(datname)), 0) / 1048576) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'" || true)
listed=$(on_source postgres "$list_query" || true)
if [ "$(jq 'length' <<< "$DATABASES")" -gt 0 ]; then
    wanted=$(jq -r '.[]' <<< "$DATABASES")
else
    wanted=$(copyable <<< "$listed")
fi
source_tables() {
    on_source "$1" "SELECT format('%I.%I.%I', current_database(), n.nspname, c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ($2) AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND $3 ORDER BY 1" || true
}
holds_rows() {
    local probe
    DATABASIFY_VAR_db="$1" pg_try postgres <<'SQL'
SELECT 1 FROM pg_database WHERE datname = :'db'
SQL
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = 1 ] || return 1
    pg_try "$1" <<'SQL'
SELECT string_agg(format('SELECT 1 FROM %I.%I', schemaname, tablename), ' UNION ALL ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
SQL
    probe=$PG_OUTPUT
    [ -z "$PG_ERROR" ] && [ -n "$probe" ] || return 1
    pg_try "$1" <<< "SELECT 1 FROM ($probe) found LIMIT 1"
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = 1 ]
}
unreadable=''
unowned=''
occupied=''
while IFS= read -r database; do
    [ -z "$database" ] && continue
    unreadable+=$(source_tables "$database" "'r', 'p', 'S'" "NOT (has_schema_privilege(n.oid, 'USAGE') AND has_table_privilege(c.oid, 'SELECT'))")$'\n'
    unowned+=$(source_tables "$database" "'r', 'p'" "NOT pg_has_role(c.relowner, 'USAGE')")$'\n'
    if holds_rows "$database"; then
        occupied+="$database"$'\n'
    fi
done <<< "$wanted"
lines() {
    jq -cR '[., inputs] | map(select(. != ""))' <<< "$1"
}
result "$(jq -cn --arg version "${version%% *}" --arg wal "$wal_level" --arg replicates "$replicates" --arg size "$size" \
    --argjson databases "$(lines "$listed")" --argjson unreadable "$(lines "$unreadable")" --argjson unowned "$(lines "$unowned")" --argjson occupied "$(lines "$occupied")" \
    '{reachable: true, error: null, version: $version, walLevel: $wal, canReplicate: ($replicates == "t"), sizeMb: (($size | tonumber?) // 0), databases: $databases, unreadable: $unreadable, unowned: $unowned, occupied: $occupied, warnings: [], problems: []}')"
