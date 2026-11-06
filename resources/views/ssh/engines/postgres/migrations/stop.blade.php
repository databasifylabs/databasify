@include('ssh.engines.postgres.migrations.helpers')
source_conninfo() { in_pg psql -X -U postgres -d "$1" -Atc "SELECT subconninfo FROM pg_subscription WHERE subname = '$2'"; }
copy_sequences() {
    conninfo=$(source_conninfo "$1" "$2")
    in_pg psql "$conninfo connect_timeout=10" -XAtc "SELECT format('SELECT setval(%L, %s, true);', format('%I.%I', schemaname, sequencename), last_value) FROM pg_sequences WHERE last_value IS NOT NULL" \
        | setpriv --reuid=postgres --regid=postgres --init-groups env HOME=/var/lib/postgresql PGHOST={{ $socketDirectory }} PGPORT={{ $clientPort }} psql -X -U postgres -d "$1" -q -v ON_ERROR_STOP=1 > /dev/null
}
databasify ctl stop databasify-postgres-migration > /dev/null 2>&1 || true
for row in $(in_pg psql -X -U postgres -d postgres -Atc "SELECT d.datname || ':' || s.subname || ':' || array_to_string(s.subpublications, ',') FROM pg_subscription s JOIN pg_database d ON d.oid = s.subdbid WHERE s.subname LIKE 'databasify_migration_%'" 2>/dev/null); do
    db=${row%%:*}
    subscription=${row#*:}
    name=${subscription%%:*}
    publication=${subscription#*:}
    copy_sequences "$db" "$name" && echo "$db: copied sequence values from the source" || echo "$db: could not copy sequence values from the source, set them before writing"
    publisher=$(source_conninfo "$db" "$name")
    in_pg psql -X -U postgres -d "$db" -qc "DROP SUBSCRIPTION \"$name\"" 2>/dev/null \
        || in_pg psql -X -U postgres -d "$db" -q -c "ALTER SUBSCRIPTION \"$name\" DISABLE" -c "ALTER SUBSCRIPTION \"$name\" SET (slot_name = NONE)" -c "DROP SUBSCRIPTION \"$name\""
    in_pg psql "$publisher connect_timeout=10" -Xqc "DROP PUBLICATION IF EXISTS \"$publication\"" 2>/dev/null || echo "$db: could not drop the $publication publication on the source, drop it there"
done
rm -rf "$state"
echo "migration stopped and cleaned up"
