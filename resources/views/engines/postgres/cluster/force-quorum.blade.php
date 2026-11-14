@include('engines.postgres._psql')
@include('engines.postgres._cluster')
@include('engines.postgres._files')
self=$(own_ip)
primary_up() {
    pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
}
if ! clustered; then
    wait_until 300 2 primary_up || databasify_fail unavailable 'PostgreSQL did not come up as primary'
    echo "This node runs alone and serves as the primary."
    exit 0
fi

forced_alone=false
wait=120
if [ "$OTHERS_DOWN" = true ]; then
    wait=30
fi
if ! wait_until "$wait" 3 etcd_healthy; then
    if [ "$OTHERS_DOWN" != true ]; then
        databasify_fail unavailable 'etcd has no majority and the other members may still be running'
    fi
    for unit in databasify-patroni databasify-pgbouncer; do
        databasify ctl stop "$unit" > /dev/null 2>&1 || true
    done
    printf 'ETCD_FORCE_NEW_CLUSTER=true\n' | write_file /srv/postgres/conf/etcd-force.env 640 || true
    unit_do restart databasify-etcd
    wait_until 120 3 etcd_healthy || true
    rm -f /srv/postgres/conf/etcd-force.env
    unit_do restart databasify-etcd
    sed "s/^  hosts: '.*'\$/  hosts: '$self:2379'/" /srv/postgres/conf/patroni.yml | write_file /srv/postgres/conf/patroni.yml 600 || true
    forced_alone=true
fi
for unit in databasify-patroni databasify-pgbouncer; do
    unit_do start "$unit"
done
leader=''
leader_found() {
    leader=$(curl -fsS --max-time 5 http://127.0.0.1:8008/cluster 2> /dev/null | jq -r 'first(.members[]? | select(.role == "leader" and .state == "running") | .name) // ""' || true)
    [ -n "$leader" ]
}
if ! wait_until 90 3 leader_found && [ "$forced_alone" = true ]; then
    HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 300 patronictl -c /srv/postgres/conf/patroni.yml failover --candidate "$self" --force > /dev/null 2>&1 || true
fi
wait_until 300 3 leader_found || databasify_fail unavailable 'no PostgreSQL leader came up'
echo "$leader leads the cluster."
