@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rewrite')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mariadb._replica')
require_saved
if saved_is '.readOnly'; then
    stop_following
    result '{}'
    exit 0
fi
write_config_files
unit_do daemon-reload
unit_do restart mariadb-server
wait_until 21600 2 serving || databasify_fail internal 'the server was not ready within 6h0m0s'
result '{}'
