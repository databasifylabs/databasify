@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mariadb._state')
@include('engines.mariadb._galera')
wait_ready MariaDB
require_saved
if ! saved_is '.clustered and (.readOnly | not)'; then
    exit 0
fi
phase 'syncing'
wait_until 21600 2 synced_now || fail_task 'the server was not ready within 6h0m0s'
keep_writer_role "$(saved .ip)"
clear_new_cluster
