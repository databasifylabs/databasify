@include('engines.postgres._psql')
@include('engines.postgres._cluster')
@include('engines.postgres._files')
@if (! $saved->clustered)
primary_up() {
    pg_try postgres <<< 'SELECT pg_is_in_recovery()'
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
}
wait_until 300 2 primary_up || databasify_fail unavailable 'PostgreSQL did not come up as primary'
echo "This node runs alone and serves as the primary."
@else
forced_alone=false
@if ($othersDown)
if ! wait_until 30 3 etcd_healthy; then
    for unit in databasify-patroni databasify-pgbouncer; do
        databasify ctl stop "$unit" > /dev/null 2>&1 || true
    done
    printf 'ETCD_FORCE_NEW_CLUSTER=true\n' | write_file /srv/postgres/conf/etcd-force.env 640 || true
    unit_do restart databasify-etcd
    wait_until 120 3 etcd_healthy || true
    rm -f /srv/postgres/conf/etcd-force.env
    unit_do restart databasify-etcd
    sed {!! $sh->arg("s/^  hosts: '.*'\$/  hosts: '{$saved->ip}:2379'/") !!} /srv/postgres/conf/patroni.yml | write_file /srv/postgres/conf/patroni.yml 600 || true
    forced_alone=true
fi
@else
wait_until 120 3 etcd_healthy || databasify_fail unavailable 'etcd has no majority and the other members may still be running'
@endif
for unit in databasify-patroni databasify-pgbouncer; do
    unit_do start "$unit"
done
leader=''
leader_found() {
    leader=$(curl -fsS --max-time 5 http://127.0.0.1:8008/cluster 2> /dev/null | jq -r 'first(.members[]? | select(.role == "leader" and .state == "running") | .name) // ""' || true)
    [ -n "$leader" ]
}
if ! wait_until 90 3 leader_found && [ "$forced_alone" = true ]; then
    as_postgres timeout 300 patronictl -c /srv/postgres/conf/patroni.yml failover --candidate {!! $sh->arg($saved->ip) !!} --force > /dev/null 2>&1 || true
fi
wait_until 300 3 leader_found || databasify_fail unavailable 'no PostgreSQL leader came up'
echo "$leader leads the cluster."
@endif
