@include('engines.mysql-family._mysql')
@include('engines.mysql-family._server')
databasify ctl reset-failed mysql-server > /dev/null 2>&1 || true
unit_do start mysql-server
wait_until 1800 2 serving || databasify_fail internal 'the server was not ready within 30m0s'
result '{}'
