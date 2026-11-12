@include('engines.postgres._psql')
row=$(pg postgres <<'SQL'
SELECT (SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend'),
  (SELECT COALESCE(sum(xact_commit + xact_rollback), 0) FROM pg_stat_database),
  (SELECT round(COALESCE(sum(pg_database_size(datname)), 0) / 1048576) FROM pg_database)
SQL
)
IFS='|' read -r connections queries data_mb extra <<< "$row"
if [ -z "$data_mb" ] || [ -n "$extra" ]; then
    databasify_fail unavailable "unexpected metrics row: $row"
fi
result "$(jq -cn --argjson connections "$connections" --argjson queries "$queries" --argjson data "$data_mb" '{connections: $connections, queries: $queries, dataMb: $data}')"
