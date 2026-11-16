require_primary_keys() {
    local tables
    tables=$(my '' <<< {!! $sh->arg("SELECT COALESCE(GROUP_CONCAT(CONCAT(t.table_schema, '.', t.table_name) ORDER BY CONCAT(t.table_schema, '.', t.table_name) SEPARATOR ', '), '') FROM information_schema.tables t WHERE t.table_type = 'BASE TABLE' AND t.table_schema NOT IN (".collect($reserved)->map(fn (string $name): string => $sql->string($name))->implode(', ').") AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')") !!})
    if [ -n "$tables" ]; then
        fail_task "a cluster needs a primary key on every table; add one to $tables"
    fi
}
