@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
unit_do start mariadb-server
require_saved
helpers=()
if saved_is '.archive != null'; then
    helpers+=(mariadb-binlog-stream)
fi
if saved_is '.clustered'; then
    helpers+=(mariadb-cluster-watch)
fi
if [ "${#helpers[@]}" -gt 0 ]; then
    unit_do start "${helpers[@]}"
fi
result '{}'
