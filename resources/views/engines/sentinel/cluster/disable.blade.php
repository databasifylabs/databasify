@include('engines.sentinel._cli')
@include('engines.sentinel._node')
databasify ctl disable "$FLAVOR-sentinel" > /dev/null 2>&1 || true
databasify ctl stop "$FLAVOR-sentinel" > /dev/null 2>&1 || true
find "$ROOT/sentinel" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2> /dev/null || true
local_cli REPLICAOF NO ONE > /dev/null
local_cli CONFIG SET min-replicas-to-write 0 > /dev/null
set_replica_of ''
result '{}'
