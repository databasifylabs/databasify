@include('engines.mysql-family._mysql')

row=$(my '' <<'SQL'
SELECT
    COALESCE((SELECT CAST(VARIABLE_VALUE AS SIGNED) FROM performance_schema.global_status WHERE VARIABLE_NAME IN ('Threads_connected', 'Questions') AND VARIABLE_NAME = 'Threads_connected'), 0),
    COALESCE((SELECT CAST(VARIABLE_VALUE AS SIGNED) FROM performance_schema.global_status WHERE VARIABLE_NAME IN ('Threads_connected', 'Questions') AND VARIABLE_NAME = 'Questions'), 0),
    (SELECT FLOOR(COALESCE(SUM(data_length + index_length), 0) / 1048576) FROM information_schema.tables)
SQL
)
IFS=$'\t' read -r connections queries data_mb extra <<< "$row"
if [ -z "$data_mb" ] || [ -n "$extra" ]; then
    databasify_fail unavailable "unexpected metrics row: $row"
fi
result "$(jq -cn --argjson connections "$connections" --argjson queries "$queries" --argjson data "$data_mb" '{connections: $connections, queries: $queries, dataMb: $data}')"
