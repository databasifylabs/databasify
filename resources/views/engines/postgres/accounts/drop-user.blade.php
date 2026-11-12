@include('engines.postgres._psql')
@include('engines.postgres._accounts')
@include('engines.postgres._primary')
exists=$(role_exists)
if [ "$exists" != t ]; then
    result '{"dropped":false}'
    exit 0
fi
listing=$(listed_databases)
while IFS= read -r database; do
    [ -z "$database" ] && continue
    DATABASIFY_VAR_name="$NAME" pg "$database" > /dev/null <<'SQL'
REASSIGN OWNED BY :"name" TO postgres;
DROP OWNED BY :"name"
SQL
done <<< "$listing"
DATABASIFY_VAR_name="$NAME" pg postgres > /dev/null <<'SQL'
DROP ROLE :"name"
SQL
result '{"dropped":true}'
