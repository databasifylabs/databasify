@include('engines.postgres._psql')
@include('engines.postgres._cluster')
patronictl_run switchover --candidate "$TARGET" --force > /dev/null
is_replica() {
    [ "$(patroni_role)" = replica ]
}
wait_until 120 2 is_replica || databasify_fail unavailable "this node is still primary after the switchover to $TARGET"
result '{}'
