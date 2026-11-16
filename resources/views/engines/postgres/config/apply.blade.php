@include('engines.postgres._psql')
@if ($clustered)
conf_load_time() {
    pg_try postgres <<< 'SELECT pg_conf_load_time()'
    printf '%s' "$PG_OUTPUT"
}

reloaded() {
    [ "$(conf_load_time)" != "$before" ]
}

before=$(conf_load_time)
unit_do reload databasify-patroni
wait_until 30 1 reloaded || true
@else
pg postgres > /dev/null <<< 'SELECT pg_reload_conf()'
@endif
unit_do reload databasify-pgbouncer
echo "Applied the settings."
