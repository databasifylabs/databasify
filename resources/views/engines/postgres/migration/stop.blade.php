@include('engines.postgres._psql')
@include('engines.postgres._source')
databasify ctl stop databasify-postgres-migration > /dev/null 2>&1 || true
pg_try postgres <<'SQL'
SELECT d.datname || '|' || s.subname || '|' || array_to_string(s.subpublications, ',') FROM pg_subscription s JOIN pg_database d ON d.oid = s.subdbid WHERE s.subname LIKE 'databasify_migration_%'
SQL
while IFS='|' read -r database subscription publication; do
    [ -z "$subscription" ] && continue
    DATABASIFY_VAR_name="$subscription" pg_try "$database" <<'SQL'
SELECT subconninfo FROM pg_subscription WHERE subname = :'name'
SQL
    conninfo=$PG_OUTPUT
    values=$(on_conninfo "$conninfo" "SELECT format('SELECT setval(%L, %s, true);', format('%I.%I', schemaname, sequencename), last_value) FROM pg_sequences WHERE last_value IS NOT NULL" || true)
    if [ -n "$values" ]; then
        pg_try "$database" <<< "$values"
    fi
    DATABASIFY_VAR_name="$subscription" pg_try "$database" <<'SQL'
DROP SUBSCRIPTION :"name"
SQL
    if [ -n "$PG_ERROR" ]; then
        DATABASIFY_VAR_name="$subscription" pg_try "$database" <<'SQL'
ALTER SUBSCRIPTION :"name" DISABLE; ALTER SUBSCRIPTION :"name" SET (slot_name = NONE); DROP SUBSCRIPTION :"name";
SQL
    fi
    on_conninfo "$conninfo" "DROP PUBLICATION IF EXISTS \"$publication\"" > /dev/null || true
done <<< "$PG_OUTPUT"
rm -rf /srv/postgres/migration/state
result '{}'
