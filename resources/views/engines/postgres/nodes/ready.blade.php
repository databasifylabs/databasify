@include('engines.postgres._psql')
@include('engines.postgres._cluster')
@php($unit = $saved->clustered ? 'databasify-patroni' : 'databasify-postgres')
@if ($saved->clustered && ! $saved->readOnly)
@php($endpoints = collect($etcdEndpoints)->map($sh->arg(...))->implode(' '))
listed_in_etcd() {
    local endpoint
    for endpoint in "$@"; do
        if [ -n "$(member_with_peer "$endpoint" {!! $sh->arg($saved->ip) !!})" ]; then
            return 0
        fi
    done
    return 1
}

echo 'Joining etcd…'
promote_learner {!! $sh->arg($saved->ip) !!} {!! $endpoints !!} || listed_in_etcd {!! $endpoints !!} || fail_task {!! $sh->arg("no etcd member lists {$saved->ip}, so it cannot join the cluster; check that etcd runs on the other members") !!}
@endif
echo 'Waiting for PostgreSQL…'
baseline=$(restarts_of {!! $sh->arg($unit) !!})
serves() {
    if [ $(( $(restarts_of {!! $sh->arg($unit) !!}) - baseline )) -gt 3 ]; then
        fail_task {!! $sh->arg("{$unit} keeps crashing, see its log") !!}
    fi
@if (! $saved->clustered)
    pg_try postgres <<< 'SELECT pg_is_in_recovery()'
@if ($expectPrimary)
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
@else
    [ -z "$PG_ERROR" ]
@endif
@elseif ($expectPrimary)
    [ "$(patroni_role)" = primary ] && [ "$(patroni_running_state)" = running ]
@else
    local state
    state=$(patroni_running_state)
    [ "$(patroni_role)" != unknown ] && { [ "$state" = running ] || [ "$state" = streaming ]; }
@endif
}
wait_until 21000 5 serves || fail_task 'PostgreSQL was not ready in time'
echo 'Waiting for the pooler…'
wait_until 300 2 listening_on 25061 || fail_task 'pgbouncer is not listening on 127.0.0.1:25061'
pg_try postgres <<< 'SELECT pg_is_in_recovery()'
if [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]; then
    pg postgres > /dev/null <<< 'CREATE EXTENSION IF NOT EXISTS pg_stat_statements'
fi
@if ($expectPrimary && $saved->archive !== null)
echo 'Switching WAL…'
pg postgres > /dev/null <<< 'SELECT pg_switch_wal(); CHECKPOINT;'
@endif
