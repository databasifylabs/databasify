@include('engines.postgres._psql')
unit_do enable databasify-postgres
unit_do restart databasify-postgres
unit_do restart databasify-pgbouncer
standalone_primary() {
    pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
}
wait_until 300 2 standalone_primary || databasify_fail unavailable 'PostgreSQL did not come back as a standalone primary'
dropped=$(pg postgres <<'SQL'
SELECT count(pg_drop_replication_slot(slot_name)) FROM pg_replication_slots WHERE slot_type = 'physical' AND NOT active
SQL
)
result "$(jq -cn --argjson dropped "${dropped:-0}" '{droppedSlots: $dropped}')"
