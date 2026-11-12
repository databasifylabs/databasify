@include('engines.postgres._psql')
STATE=/srv/postgres/migration/state
exit_code=$(tr -d '[:space:]' < "$STATE/copy.exit" 2> /dev/null || true)
if [ "$exit_code" = 0 ]; then
    copy=succeeded
elif [ -n "$exit_code" ]; then
    copy=failed
elif unit_active databasify-postgres-migration; then
    copy=running
else
    copy=lost
fi
log=$(tail -n 2 /srv/postgres/logs/databasify-postgres-migration.log 2> /dev/null | tr '\n' ' ' | sed 's/ $//' || true)
pg_try postgres <<'SQL'
SELECT COALESCE((CASE WHEN st.last_msg_send_time IS NULL THEN NULL WHEN st.latest_end_lsn >= st.received_lsn AND now() - st.last_msg_receipt_time < interval '90 seconds' THEN 0 ELSE GREATEST(round(extract(epoch FROM now() - st.last_msg_send_time)), 0) END)::text, 'unknown') FROM pg_subscription s LEFT JOIN pg_stat_subscription st ON st.subid = s.oid AND st.relid IS NULL AND st.worker_type = 'apply' WHERE s.subname LIKE 'databasify_migration_%'
SQL
lag=$(grep -v '^unknown$' <<< "$PG_OUTPUT" | grep . | sort -n | tail -n 1 || true)
result "$(jq -cn --arg copy "$copy" --arg log "$log" --arg lag "$lag" '{copy: $copy, log: $log, lagSeconds: (if $lag == "" then null else ($lag | tonumber) end)}')"
