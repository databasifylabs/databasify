@include('engines.postgres._psql')
@include('engines.postgres._cluster')
unit=$(main_unit)
if clustered && [ -e /srv/postgres/conf/etcd.env ]; then
    phase 'joining etcd'
    promote_own_learner
fi
phase 'waiting for PostgreSQL'
baseline=$(restarts_of "$unit")
serves() {
    if [ $(( $(restarts_of "$unit") - baseline )) -gt 3 ]; then
        fail_task "$unit keeps crashing, see its log"
    fi
    if ! clustered; then
        pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
        [ -z "$PG_ERROR" ] && { [ "$EXPECT_PRIMARY" != true ] || [ "$PG_OUTPUT" = f ]; }
        return
    fi
    if [ "$EXPECT_PRIMARY" = true ]; then
        [ "$(patroni_role)" = primary ] && [ "$(patroni_running_state)" = running ]
        return
    fi
    local state
    state=$(patroni_running_state)
    [ "$(patroni_role)" != unknown ] && { [ "$state" = running ] || [ "$state" = streaming ]; }
}
wait_until 21000 5 serves || fail_task 'PostgreSQL was not ready in time'
phase 'waiting for the pooler'
pooler_listening() {
    timeout 1 bash -c 'exec 3<>/dev/tcp/127.0.0.1/25061' 2> /dev/null
}
wait_until 300 2 pooler_listening || fail_task 'pgbouncer is not listening on 127.0.0.1:25061'
pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
if [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]; then
    pg postgres > /dev/null <<'SQL'
CREATE EXTENSION IF NOT EXISTS pg_stat_statements
SQL
fi
if [ "$EXPECT_PRIMARY" = true ] && [ -e /srv/postgres/conf/pgbackrest.conf ]; then
    phase 'switching WAL'
    pg postgres > /dev/null <<'SQL'
SELECT pg_switch_wal(); CHECKPOINT;
SQL
fi
