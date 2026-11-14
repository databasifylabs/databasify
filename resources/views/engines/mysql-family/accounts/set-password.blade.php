@include('engines.mysql-family._mysql')
@include('engines.mysql-family._accounts')
require_user
my '' > /dev/null <<SQL
ALTER USER $quoted_name@'%' ${IDENTIFIED_BY}$(my_string "$PASSWORD")${IDENTIFIED_CLOSE}
SQL
echo "Set a new password for $NAME."
