@include('engines.postgres._psql')
@include('engines.postgres._cluster')
unit_do daemon-reload
if [ -e /srv/postgres/conf/etcd.env ]; then
    phase 'starting etcd'
    unit_do enable databasify-etcd
    unit_do start databasify-etcd
    phase 'joining etcd'
    promote_own_learner
    wait_until 120 3 etcd_healthy || fail_task 'etcd did not become healthy'
fi
phase 'starting Patroni'
databasify ctl reset-failed databasify-patroni > /dev/null 2>&1 || true
unit_do enable databasify-patroni
unit_do start databasify-patroni
baseline=$(restarts_of databasify-patroni)
led() {
    if [ $(( $(restarts_of databasify-patroni) - baseline )) -gt 3 ]; then
        fail_task 'databasify-patroni keeps crashing, see its log'
    fi
    settled
}
wait_until 600 3 led || fail_task 'Patroni did not lead or follow the cluster in time'
unit_do restart databasify-pgbouncer
