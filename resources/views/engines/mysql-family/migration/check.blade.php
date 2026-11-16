@include('engines.mysql-family._mysql')
@include('engines.mysql-family._source')
SOURCE_CNF=$(source_cnf)
trap 'rm -f "$SOURCE_CNF"' EXIT

on_source() {
@if ($engine->id === 'mysql')
    MY_DEFAULTS=$SOURCE_CNF MY_SESSION_EXTRA='SESSION information_schema_stats_expiry = 0' my_try ''
@else
    MY_DEFAULTS=$SOURCE_CNF my_try ''
@endif
}

@verbatim
on_source <<< 'SELECT @@version'
@endverbatim
if [ -n "$MY_ERROR" ]; then
    result "$(jq -cn --arg error "$MY_ERROR_TEXT" '{error: $error}')"
    exit 0
fi
version=$MY_OUTPUT
on_source <<'SQL'
SELECT JSON_OBJECT(
    'visible', (SELECT COALESCE(JSON_ARRAYAGG(SCHEMA_NAME), JSON_ARRAY()) FROM information_schema.SCHEMATA),
    'sizes', (SELECT COALESCE(JSON_OBJECTAGG(table_schema, bytes), JSON_OBJECT()) FROM (SELECT table_schema, CAST(SUM(data_length + index_length) AS UNSIGNED) AS bytes FROM information_schema.tables GROUP BY table_schema) AS sized),
    'missingKeys', (SELECT COALESCE(JSON_OBJECTAGG(table_schema, missing), JSON_OBJECT()) FROM (SELECT t.table_schema, COUNT(*) AS missing FROM information_schema.tables t WHERE t.table_type = 'BASE TABLE' AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY') GROUP BY t.table_schema) AS keyless)
)
SQL
[ -n "$MY_ERROR" ] && databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
source_facts=$MY_OUTPUT
MY_HEADERS='' on_source <<< 'SHOW GRANTS'
grants=$MY_OUTPUT
local_facts=$(my '' <<'SQL'
SELECT JSON_OBJECT(
@verbatim
    'localVersion', @@version,
@endverbatim
    'localTables', (SELECT COALESCE(JSON_OBJECTAGG(table_schema, tables), JSON_OBJECT()) FROM (SELECT table_schema, COUNT(*) AS tables FROM information_schema.tables GROUP BY table_schema) AS counted)
)
SQL
)
@if ($engine->id === 'mysql')
@verbatim
on_source <<< 'SELECT @@gtid_mode'
@endverbatim
gtid_mode=$MY_OUTPUT
on_source <<< 'SELECT 1 FROM mysql.role_edges LIMIT 1'
role_edges=true
[ -n "$MY_ERROR" ] && role_edges=false
extra=$(jq -cn --arg gtid "$gtid_mode" --argjson roles "$role_edges" '{gtidMode: $gtid, roleEdgesReadable: $roles}')
@else
@verbatim
on_source <<< 'SELECT @@log_bin, @@binlog_format'
@endverbatim
IFS=$'\t' read -r log_bin binlog_format <<< "$MY_OUTPUT"
extra=$(jq -cn --arg logBin "${log_bin:-}" --arg format "${binlog_format:-}" '{logBin: $logBin, binlogFormat: $format}')
@endif
result "$(jq -cn --arg version "$version" --arg grants "$grants" --argjson source "$source_facts" --argjson local "$local_facts" --argjson extra "$extra" '{error: null, version: $version, grants: $grants} + $source + $local + $extra')"
