@include('ssh.engines.postgres.migrations.helpers')
if in_pg test -f "$state/copy.exit"; then
    printf 'copy_exit=%s\n' "$(in_pg cat "$state/copy.exit")"
elif in_pg test -f "$state/copy.sh"; then
    in_pg pgrep -f "$state/copy.sh" > /dev/null && echo "copy=running" || echo "copy=lost"
fi
printf 'log=%s\n' "$(in_pg tail -n 2 "$state/copy.log" 2>/dev/null | tr '\n' ' ')"
for db in $(in_pg psql -X -U postgres -d postgres -Atc "SELECT d.datname FROM pg_subscription s JOIN pg_database d ON d.oid = s.subdbid WHERE s.subname LIKE 'databasify_migration_%'" 2>/dev/null); do
    in_pg psql -X -U postgres -d "$db" -Atc "SELECT 'tables=' || count(*) || ' ready=' || count(*) FILTER (WHERE srsubstate = 'r') FROM pg_subscription_rel" 2>/dev/null
done | awk -F '[= ]' '{ tables += $2; ready += $4 } END { if (NR > 0) printf "subscribed_tables=%d\nready_tables=%d\n", tables, ready }'
in_pg psql -X -U postgres -d postgres -Atc "SELECT 'lags=' || COALESCE(string_agg(COALESCE((CASE WHEN st.last_msg_send_time IS NULL THEN NULL WHEN st.latest_end_lsn >= st.received_lsn AND now() - st.last_msg_receipt_time < interval '90 seconds' THEN 0 ELSE GREATEST(round(extract(epoch FROM now() - st.last_msg_send_time)), 0) END)::text, 'unknown'), ' '), '') FROM pg_subscription s LEFT JOIN pg_stat_subscription st ON st.subid = s.oid AND st.relid IS NULL AND st.worker_type = 'apply' WHERE s.subname LIKE 'databasify_migration_%'" 2>/dev/null || true
