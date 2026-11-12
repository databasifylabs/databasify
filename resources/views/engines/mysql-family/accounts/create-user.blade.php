@include('engines.mysql-family._mysql')
@include('engines.mysql-family._accounts')
require_databases
identified="${IDENTIFIED_BY}$(my_string "$PASSWORD")${IDENTIFIED_CLOSE}"
if user_exists; then
    created=false
    my '' > /dev/null <<SQL
ALTER USER $quoted_name@'%' $identified
SQL
else
    created=true
    my '' > /dev/null <<SQL
CREATE USER $quoted_name@'%' $identified REQUIRE SSL
SQL
fi
apply_grants
result "{\"created\":$created}"
