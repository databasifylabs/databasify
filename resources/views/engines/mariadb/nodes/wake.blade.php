@include('engines.mysql-family._mysql')
databasify ctl kill -s KILL mariadb-server > /dev/null 2>&1 || true
unit_do stop mariadb-server
result '{}'
