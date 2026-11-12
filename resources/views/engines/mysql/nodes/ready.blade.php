@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
wait_ready MySQL
if [ -e "$ROOT/state/grouped" ]; then
    replication_password
    phase 'joining the group'
    start_group "$REPLICATION_SECRET" || fail_task "could not rejoin the group: $MY_ERROR_TEXT"
    online_and_joined "$REPLICATION_SECRET"
fi
