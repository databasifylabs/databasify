@include('engines.mysql-family._mysql')
my '' > /dev/null <<SQL
CREATE DATABASE IF NOT EXISTS $(my_name "$NAME")
SQL
echo "Created database $NAME."
