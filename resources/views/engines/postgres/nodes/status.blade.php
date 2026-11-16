@include('engines.postgres._psql')
patroni=null
if unit_active databasify-patroni; then
    patroni=$(patroni_state | jq -c . 2> /dev/null || echo '{}')
fi
pg_try postgres <<'SQL'
SELECT json_build_object('recovery', pg_is_in_recovery(), 'version', split_part(current_setting('server_version'), ' ', 1))
SQL
if [ -n "$PG_ERROR" ] && [ "$PG_ERROR" != unavailable ]; then
    databasify_fail "$PG_ERROR" "$PG_ERROR_TEXT"
fi
output=$(jq -cn --argjson patroni "$patroni" --argjson server "${PG_OUTPUT:-null}" '{patroni: $patroni, server: $server}')
result "$output"
