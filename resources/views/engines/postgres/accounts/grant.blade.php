@include('engines.postgres._psql')
@include('engines.postgres._accounts')
@include('engines.postgres._primary')
require_role
require_databases
apply_grants
if [ "$REPLICATION" = true ]; then
    DATABASIFY_VAR_name="$NAME" pg postgres > /dev/null <<'SQL'
ALTER ROLE :"name" REPLICATION
SQL
fi
echo "Granted $NAME its databases."
