@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
running=$(my '' <<'SQL'
SELECT SERVICE_STATE FROM performance_schema.replication_connection_status WHERE CHANNEL_NAME = 'group_replication_applier'
SQL
) || running=''
if [ "$running" != ON ]; then
    REPLICATION_SECRET={!! $sh->arg((string) $saved->secrets->replication) !!}
    [ -n "$REPLICATION_SECRET" ] || databasify_fail invalid_request 'MySQL clusters need the replication secret'
    bootstrap_group "$REPLICATION_SECRET"
    online_and_joined "$REPLICATION_SECRET"
    echo "This node already has a working group."
    exit 0
fi
my '' > /dev/null <<SQL
SET GLOBAL group_replication_force_members = {!! $sql->string($saved->ip.':33061') !!}
SQL
online_and_joined ''
my '' > /dev/null <<< "SET GLOBAL group_replication_force_members = ''"
echo "Restarted the group on this node."
