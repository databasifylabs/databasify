@include('engines.mysql-family._mysql')
@include('engines.mysql-family._accounts')
require_databases
require_user
apply_grants
if [ "$REPLICATION" = true ]; then
    my '' > /dev/null <<SQL
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO $quoted_name@'%'
SQL
fi
echo "Granted $NAME its databases."
