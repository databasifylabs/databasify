@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
@if ($readOnly)
echo 'Starting replication…'
my '' > /dev/null <<< "START REPLICA FOR CHANNEL 'group'"
wait_until 1800 2 channel_running || fail_task 'the server was not ready within 30m0s'
exit 0
@endif
[ -n "$REPLICATION_SECRET" ] || databasify_fail invalid_request 'MySQL clusters need the replication secret'
my '' > /dev/null <<< {!! $sh->arg('SET GLOBAL group_replication_ip_allowlist = '.$sql->string($allowlist)) !!}
echo 'Joining the group…'
join_group "$REPLICATION_SECRET" || fail_task "$MY_ERROR_TEXT"
echo 'Waiting until the member is online…'
online_and_joined "$REPLICATION_SECRET"
