@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mariadb._state')
@include('engines.mariadb._replica')
if [ "$READ_ONLY" = true ]; then
    echo 'Pointing replication at a synced member…'
    require_saved
    writer_to_follow
    replication_password
    follow_writer "$WRITER" "$REPLICATION_SECRET"
    exit 0
fi
echo 'Starting MariaDB…'
unit_do start mariadb-server
echo 'Waiting for Galera to sync…'
wait_until 600 2 synced_now || fail_task 'the server was not ready within 10m0s'
