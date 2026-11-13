@include('engines.postgres._psql')
@include('engines.postgres._primary')
dropped=$(DATABASIFY_VAR_name="$NAME" pg postgres <<'SQL'
SELECT EXISTS (SELECT FROM pg_database WHERE datname = :'name') AS dropped \gset
DROP DATABASE IF EXISTS :"name" WITH (FORCE);
SELECT :'dropped'
SQL
)
[ "$dropped" = t ] && echo "Dropped database $NAME." || echo "Database $NAME was already gone."
