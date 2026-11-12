@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
if [ "$READ_ONLY" = true ]; then
    phase 'starting replication'
    my '' > /dev/null <<< "START REPLICA FOR CHANNEL 'group'"
    wait_until 1800 2 channel_running || fail_task 'the server was not ready within 30m0s'
    exit 0
fi
replication_password
phase 'joining the group'
join_group "$REPLICATION_SECRET" || fail_task "$MY_ERROR_TEXT"
phase 'waiting until the member is online'
online_and_joined "$REPLICATION_SECRET"
