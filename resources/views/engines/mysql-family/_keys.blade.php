require_primary_keys() {
    local reserved tables
    reserved=$(jq -r 'map(@base64) | .[]' <<< "$RESERVED_DATABASES" | while IFS= read -r name; do my_string "$(base64 -d <<< "$name")"; printf ', '; done)
    tables=$(my '' <<SQL
SELECT COALESCE(GROUP_CONCAT(CONCAT(t.table_schema, '.', t.table_name) ORDER BY CONCAT(t.table_schema, '.', t.table_name) SEPARATOR ', '), '') FROM information_schema.tables t WHERE t.table_type = 'BASE TABLE' AND t.table_schema NOT IN (${reserved%, }) AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')
SQL
)
    if [ -n "$tables" ]; then
        fail_task "a cluster needs a primary key on every table; add one to $tables"
    fi
}
