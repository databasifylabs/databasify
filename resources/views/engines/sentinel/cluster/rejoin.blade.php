@include('engines.sentinel._cli')
@include('engines.sentinel._node')
databasify ctl reset-failed "$FLAVOR-server" "$FLAVOR-sentinel" > /dev/null 2>&1 || true
databasify ctl start "$FLAVOR-sentinel" > /dev/null 2>&1 || true
phase 'finding the primary'
found() {
    newest_primary
    [ -n "$NEWEST_PRIMARY" ]
}
until_ok 120 2m0s found
replicate=''
if [ "$NEWEST_PRIMARY" != "$SELF" ]; then
    replicate=$NEWEST_PRIMARY
fi
set_replica_of "$replicate"
if [ "$READ_ONLY" != true ] && restart_loses_data "$PERSISTENT"; then
    hold
fi
phase 'replicating from the primary'
if ! unit_active "$FLAVOR-server"; then
    unit_do start "$FLAVOR-server"
elif [ -z "$replicate" ]; then
    local_cli REPLICAOF NO ONE > /dev/null || fail_task 'could not stop replicating'
else
    local_cli REPLICAOF "$replicate" 6379 > /dev/null || fail_task "could not replicate from $replicate"
fi
if [ -z "$replicate" ]; then
    exit 0
fi
until_ok 600 10m0s replica_synced
if [ "$READ_ONLY" != true ] && holding; then
    release
fi
