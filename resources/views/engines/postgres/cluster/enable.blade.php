@include('engines.postgres._psql')
@include('engines.postgres._cluster')
unit_do daemon-reload
@if ($saved->clustered && ! $saved->readOnly)
echo 'Starting etcd…'
unit_do enable databasify-etcd
unit_do start databasify-etcd
echo 'Joining etcd…'
promote_learner {!! $sh->arg($saved->ip) !!} {!! collect($etcdEndpoints)->map($sh->arg(...))->implode(' ') !!} || true
wait_until 120 3 etcd_healthy || fail_task 'etcd did not become healthy'
@endif
echo 'Starting Patroni…'
databasify ctl reset-failed databasify-patroni > /dev/null 2>&1 || true
unit_do enable databasify-patroni
unit_do start databasify-patroni
baseline=$(restarts_of databasify-patroni)
led() {
    local role state
    if [ $(( $(restarts_of databasify-patroni) - baseline )) -gt 3 ]; then
        fail_task 'databasify-patroni keeps crashing, see its log'
    fi
    role=$(patroni_role)
    state=$(patroni_running_state)
    { [ "$state" = running ] || [ "$state" = streaming ]; } && { [ "$role" = primary ] || [ "$role" = replica ]; }
}
wait_until 600 3 led || fail_task 'Patroni did not lead or follow the cluster in time'
unit_do restart databasify-pgbouncer
