@include('engines.postgres._psql')
@include('engines.postgres._source')
databasify ctl stop databasify-postgres-migration > /dev/null 2>&1 || true
pg_try postgres <<'SQL'
SELECT d.datname || '|' || s.subname || '|' || array_to_string(s.subpublications, ',') FROM pg_subscription s JOIN pg_database d ON d.oid = s.subdbid WHERE s.subname LIKE 'databasify_migration_%'
SQL
while IFS='|' read -r database subscription publication; do
    [ -z "$subscription" ] && continue
    pg_try "$database" <<< "SELECT subconninfo FROM pg_subscription WHERE subname = $(pg_string "$subscription")"
    conninfo=$PG_OUTPUT
    values=$(on_conninfo "$conninfo" "SELECT format('SELECT setval(%L, %s, true);', format('%I.%I', schemaname, sequencename), last_value) FROM pg_sequences WHERE last_value IS NOT NULL" || true)
    if [ -n "$values" ]; then
        pg_try "$database" <<< "$values"
    fi
    pg_try "$database" <<< "DROP SUBSCRIPTION $(pg_name "$subscription")"
    if [ -n "$PG_ERROR" ]; then
        pg_try "$database" <<< "SELECT subslotname FROM pg_subscription WHERE subname = $(pg_string "$subscription")"
        slot=$PG_OUTPUT
        pg_try "$database" <<< "ALTER SUBSCRIPTION $(pg_name "$subscription") DISABLE; ALTER SUBSCRIPTION $(pg_name "$subscription") SET (slot_name = NONE); DROP SUBSCRIPTION $(pg_name "$subscription");"
        if [ -z "$PG_ERROR" ] && [[ $slot =~ ^[a-z0-9_]+$ ]]; then
            on_conninfo "$conninfo" "SELECT pg_drop_replication_slot(slot_name) FROM pg_replication_slots WHERE slot_name = '$slot'" > /dev/null \
                || echo "$database: could not drop the $slot replication slot on the source, drop it there"
        fi
    fi
    on_conninfo "$conninfo" "DROP PUBLICATION IF EXISTS $(pg_name "$publication")" > /dev/null || true
done <<< "$PG_OUTPUT"
rm -rf /srv/postgres/migration/state
echo "Stopped the migration."
