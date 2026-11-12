@include('engines.postgres._psql')
conf_load_time() {
    pg_try postgres <<'SQL'
SELECT pg_conf_load_time()
SQL
    printf '%s' "$PG_OUTPUT"
}

reloaded() {
    [ "$(conf_load_time)" != "$before" ]
}

if clustered; then
    before=$(conf_load_time)
    unit_do reload databasify-patroni
    wait_until 30 1 reloaded || true
else
    pg postgres > /dev/null <<'SQL'
SELECT pg_reload_conf()
SQL
fi
unit_do reload databasify-pgbouncer
result '{}'
