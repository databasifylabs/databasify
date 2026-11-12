@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
require_saved
running=$(my '' <<'SQL'
SELECT SERVICE_STATE FROM performance_schema.replication_connection_status WHERE CHANNEL_NAME = 'group_replication_applier'
SQL
) || running=''
if [ "$running" != ON ]; then
    replication_password
    bootstrap_group "$REPLICATION_SECRET"
    online_and_joined "$REPLICATION_SECRET"
    result '{}'
    exit 0
fi
my '' > /dev/null <<SQL
SET GLOBAL group_replication_force_members = $(my_string "$(saved .ip):33061")
SQL
online_and_joined ''
my '' > /dev/null <<< "SET GLOBAL group_replication_force_members = ''"
result '{}'
