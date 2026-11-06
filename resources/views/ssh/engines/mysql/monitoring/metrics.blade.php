msql "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running', 'Questions', 'Uptime', 'Slow_queries')" | awk '{ print tolower($1) "=" $2 }'
msql "SELECT COALESCE(ROUND(SUM(data_length + index_length) / 1048576), 0) FROM information_schema.tables" | sed 's/^/data_mb=/'
{ own_state || echo 'UNKNOWN OFFLINE'; } | tr '\t' ' ' | sed 's/^/state=/'
