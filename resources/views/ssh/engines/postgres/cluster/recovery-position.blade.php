@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
position=""
if databasify ctl is-active --quiet databasify-patroni databasify-postgres; then
    position=$(pg "SELECT pg_wal_lsn_diff(CASE WHEN pg_is_in_recovery() THEN GREATEST(pg_last_wal_receive_lsn(), pg_last_wal_replay_lsn()) ELSE pg_current_wal_lsn() END, '0/0')::bigint" || true)
fi
if [ -z "$position" ]; then
    controldata=$({ ls /usr/lib/postgresql/*/bin/pg_controldata 2> /dev/null || true; } | tail -n 1)
    for lsn in $({ as_postgres "$controldata" "$dir/data" 2> /dev/null || true; } | sed -n "s/^\(Latest checkpoint location\|Minimum recovery ending location\): *//p"); do
        bytes=$(( (16#${lsn%/*} << 32) + 16#${lsn#*/} ))
        [ -n "$position" ] && [ "$position" -ge "$bytes" ] || position=$bytes
    done
fi
echo "position=${position:--1}"
