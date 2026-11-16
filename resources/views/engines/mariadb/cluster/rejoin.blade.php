@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mariadb._state')
@include('engines.mariadb._replica')
@if ($readOnly)
echo 'Pointing replication at a synced member…'
[ -n "$REPLICATION_SECRET" ] || databasify_fail invalid_request 'MariaDB clusters need the replication secret'
WRITER={!! $sh->arg((string) $saved->primary) !!}
[ -n "$WRITER" ] || fail_task 'a read-only node needs the writer to follow'
follow_writer "$WRITER" "$REPLICATION_SECRET"
exit 0
@endif
echo 'Starting MariaDB…'
unit_do start mariadb-server
echo 'Waiting for Galera to sync…'
wait_until 600 2 synced_now || fail_task 'the server was not ready within 10m0s'
