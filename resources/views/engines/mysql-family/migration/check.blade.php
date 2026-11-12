@include('engines.mysql-family._mysql')
@include('engines.mysql-family._source')
SOURCE_CNF=$(source_cnf)
trap 'rm -f "$SOURCE_CNF"' EXIT

on_source() {
    MY_DEFAULTS=$SOURCE_CNF my_try ''
}

on_source <<'SQL'
@verbatim
SELECT @@version
@endverbatim
SQL
if [ -n "$MY_ERROR" ]; then
    result "$(jq -cn --arg error "$MY_ERROR_TEXT" '{error: $error}')"
    exit 0
fi
version=$MY_OUTPUT
on_source <<'SQL'
SELECT COALESCE(JSON_ARRAYAGG(SCHEMA_NAME), JSON_ARRAY()) FROM information_schema.SCHEMATA
SQL
[ -n "$MY_ERROR" ] && databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
visible=$(jq -c --argjson reserved "$RESERVED_DATABASES" 'map(select(. as $name | $reserved | index($name) | not)) | sort' <<< "$MY_OUTPUT")
wanted=$DATABASES
if [ "$(jq length <<< "$wanted")" = 0 ]; then
    wanted=$visible
fi
sizes='{}'
local_tables='{}'
missing_keys='{}'
while IFS= read -r encoded; do
    [ -z "$encoded" ] && continue
    database=$(base64 -d <<< "$encoded")
    quoted=$(my_string "$database")
    on_source <<SQL
SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = $quoted
SQL
    size=${MY_OUTPUT:-0}
    my_try '' <<SQL
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = $quoted
SQL
    count=${MY_OUTPUT:-0}
    on_source <<SQL
SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = $quoted AND t.table_type = 'BASE TABLE' AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')
SQL
    missing=${MY_OUTPUT:-0}
    sizes=$(jq -c --arg name "$database" --arg value "$size" '. + {($name): ($value | tonumber? // 0)}' <<< "$sizes")
    local_tables=$(jq -c --arg name "$database" --arg value "$count" '. + {($name): ($value | tonumber? // 0)}' <<< "$local_tables")
    missing_keys=$(jq -c --arg name "$database" --arg value "$missing" '. + {($name): ($value | tonumber? // 0)}' <<< "$missing_keys")
done < <(jq -r --argjson visible "$visible" '.[] | select(. as $name | $visible | index($name)) | @base64' <<< "$wanted")
MY_HEADERS='' on_source <<< 'SHOW GRANTS'
grants=$(jq -Rsc 'split("\n") | map(select(. != ""))' <<< "$MY_OUTPUT")
local_version=$(my '' <<'SQL'
@verbatim
SELECT @@version
@endverbatim
SQL
)
@if ($engine->id === 'mysql')
on_source <<'SQL'
@verbatim
SELECT @@gtid_mode
@endverbatim
SQL
gtid_mode=$MY_OUTPUT
on_source <<< 'SELECT 1 FROM mysql.role_edges LIMIT 1'
role_edges=true
if [ -n "$MY_ERROR" ]; then
    role_edges=false
fi
extra=$(jq -cn --arg gtid "$gtid_mode" --argjson roles "$role_edges" '{gtidMode: $gtid, roleEdgesReadable: $roles}')
@else
on_source <<'SQL'
@verbatim
SELECT @@log_bin, @@binlog_format
@endverbatim
SQL
IFS=$'\t' read -r log_bin binlog_format <<< "$MY_OUTPUT"
extra=$(jq -cn --arg logBin "${log_bin:-}" --arg format "${binlog_format:-}" '{logBin: $logBin, binlogFormat: $format}')
@endif
result "$(jq -cn --arg version "$version" --argjson visible "$visible" --argjson sizes "$sizes" --argjson local "$local_tables" --argjson missing "$missing_keys" --argjson grants "$grants" --arg localVersion "$local_version" --argjson extra "$extra" '{error: null, version: $version, visible: $visible, sizes: $sizes, localTables: $local, missingKeys: $missing, grants: $grants, localVersion: $localVersion} + $extra')"
