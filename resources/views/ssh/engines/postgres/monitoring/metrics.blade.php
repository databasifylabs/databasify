pg "SELECT 'threads_connected=' || count(*) FROM pg_stat_activity WHERE backend_type = 'client backend'"
pg "SELECT 'questions=' || COALESCE(sum(xact_commit + xact_rollback), 0) FROM pg_stat_database"
pg "SELECT 'data_mb=' || round(COALESCE(sum(pg_database_size(datname)), 0) / 1048576) FROM pg_database"
node_state | sed "s/^/state=/"
