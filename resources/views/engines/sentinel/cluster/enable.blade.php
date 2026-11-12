@include('engines.sentinel._cli')
@include('engines.sentinel._node')
phase 'starting sentinel'
local_cli CONFIG SET replica-announce-ip "$SELF" min-replicas-to-write "$REQUIRED" min-replicas-max-lag 10 > /dev/null || fail_task 'could not set the replication settings'
unit_do enable "$FLAVOR-sentinel"
unit_do start "$FLAVOR-sentinel"
until_ok 60 1m0s sentinel_answers
