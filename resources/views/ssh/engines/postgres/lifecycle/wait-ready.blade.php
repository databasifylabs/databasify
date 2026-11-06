@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
main_unit={{ $clustered ? 'databasify-patroni' : 'databasify-postgres' }}
ready() {
    if crash_looping "$main_unit" 3; then
        echo "postgres keeps stopping while it starts"
        exit 1
    fi
    state=$(node_state)
@if ($expectPrimary)
    printf '%s' "$state" | grep -q '^role:primary state:running'
@else
    printf '%s' "$state" | grep -Eq '^role:(replica|primary) state:(running|streaming)'
@endif
}
pool_up() { ss -ltn | grep -q ':{{ $poolPort }} '; }
@if ($clustered && ! $readOnly)
endpoint=$(answering_etcd {{ $privateIp }} || true)
learner=$({ [ -z "$endpoint" ] || timeout 30 etcdctl --endpoints="$endpoint" member list < /dev/null || true; } | awk -F ', ' -v peer="http://{{ $privateIp }}:{{ $etcdPeerPort }}" '$4 == peer && $6 == "true" { print $1 }')
if [ -n "$learner" ]; then
    promoted() { timeout 30 etcdctl --endpoints="$endpoint" member promote "$learner" < /dev/null > /dev/null 2>&1; }
    wait_for 300 promoted
    echo "etcd on {{ $privateIp }} caught up and now votes again"
fi
@endif
wait_for {{ $deadline }} ready
wait_for 300 pool_up
if [ "$(own_role)" = "primary" ] && [ "$(pg 'SHOW archive_mode' || true)" = "on" ]; then
    pg "SELECT pg_switch_wal()" > /dev/null
    pg "CHECKPOINT" > /dev/null
    echo "WAL switched and checkpointed so archiving resumes right after the start"
fi
node_state
