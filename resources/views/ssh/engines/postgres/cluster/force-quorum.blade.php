@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
@if (! $clustered)
serving() { [ "$(own_role)" = "primary" ]; }
wait_for 300 serving
echo "postgres serves writes on this node"
@else
etcd_up() { etcd_ctl endpoint health > /dev/null 2>&1; }
if ! wait_for {{ $othersAreDown ? 30 : 120 }} etcd_up > /dev/null; then
@if ($othersAreDown)
    databasify ctl stop databasify-patroni databasify-pgbouncer
    forced="$dir/conf/etcd-force.env"
    echo 'ETCD_FORCE_NEW_CLUSTER=true' | put_file "$forced" 640 postgres
    databasify ctl restart databasify-etcd
    wait_for 60 etcd_up
    rm -f "$forced"
    databasify ctl restart databasify-etcd
    wait_for 60 etcd_up
    sed -i "s|^  hosts: .*|  hosts: '{{ $privateIp }}:{{ $etcdClientPort }}'|" "$dir/conf/patroni.yml"
    forced_alone=yes
    echo "etcd on {{ $privateIp }} now runs as a single-member cluster, the lost members join again when they are redeployed"
@else
    echo "etcd has no majority, start the other members' servers and recover again"
    exit 1
@endif
fi
databasify ctl reset-failed databasify-patroni > /dev/null 2>&1 || true
databasify ctl start databasify-patroni databasify-pgbouncer
cluster() { timeout 10 curl -s --max-time 5 http://127.0.0.1:{{ $patroniPort }}/cluster < /dev/null 2> /dev/null; }
has_leader() { cluster | jq -e '[.members[]? | select(.role == "leader" and .state == "running")] | length == 1' > /dev/null 2>&1; }
@if ($othersAreDown)
if [ -n "${forced_alone:-}" ] && ! wait_for 90 has_leader > /dev/null; then
    patroni_ctl failover --candidate {{ $privateIp }} --force > /dev/null
    echo "patroni would not promote {{ $privateIp }} on its own because it lags the lost primary, failed over to it on request"
fi
@endif
wait_for 300 has_leader
@if ($othersAreDown)
serving() { [ "$(own_role)" = "primary" ]; }
[ -z "${forced_alone:-}" ] || wait_for 120 serving
@endif
echo "patroni elected $(cluster | jq -r '.members[] | select(.role == "leader") | .name') as the primary, the other members follow it"
@endif
