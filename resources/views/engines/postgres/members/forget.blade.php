@include('engines.postgres._psql')
@include('engines.postgres._cluster')
member=$(member_with_peer "$LOCAL_ETCD" "$IP" || true)
if [ -n "$member" ]; then
    read -r id learner <<< "$member"
    removed=$(etcd_ctl "$LOCAL_ETCD" member remove "$id" 2>&1) || databasify_fail internal "etcdctl member: $removed"
fi
result '{}'
