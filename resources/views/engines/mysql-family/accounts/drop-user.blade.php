@include('engines.mysql-family._mysql')
@include('engines.mysql-family._accounts')
if user_exists; then
    my '' > /dev/null <<SQL
DROP USER IF EXISTS $quoted_name@'%'
SQL
fi
echo "Dropped user $NAME."
