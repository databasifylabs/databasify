@include('engines.postgres._psql')
@include('engines.postgres._backrest')
info=$(backrest /srv/postgres/conf/pgbackrest.conf info --output=json 2>&1) || {
    output=$(jq -cn --arg error "$(tail -n 5 <<< "$info")" '{error: $error}')
    result "$output"
    exit 0
}
beats_file=/srv/postgres/archive/state/heartbeats
beats=$(grep . "$beats_file" 2> /dev/null || true)
pg_try postgres <<< 'SELECT floor(extract(epoch FROM now()))::bigint, pg_walfile_name(pg_current_wal_insert_lsn())'
if [ -z "$PG_ERROR" ] && [ -n "$PG_OUTPUT" ]; then
    beats=$(printf '%s\n%s' "$beats" "${PG_OUTPUT//|/ }" | grep .)
fi
beats=$(tail -n 50 <<< "$beats")
printf '%s\n' "$beats" > "$beats_file"
chmod 600 "$beats_file"
pg_try postgres <<'SQL'
SELECT json_build_object('lastArchivedWal', COALESCE(last_archived_wal, ''), 'archiveFailure', CASE WHEN last_failed_time IS NOT NULL AND (last_archived_time IS NULL OR last_failed_time > last_archived_time) THEN 'WAL ' || last_failed_wal || ' failed to archive at ' || to_char(last_failed_time AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC.' ELSE '' END) FROM pg_stat_archiver
SQL
archiver=${PG_OUTPUT:-'{"lastArchivedWal":"","archiveFailure":""}'}
backup_result=$(databasify ctl show -p Result --value databasify-postgres-backup 2> /dev/null || true)
output=$(jq -cn --arg info "$info" --arg heartbeats "$beats" --argjson archiver "$archiver" --arg backupResult "$backup_result" '{info: $info, heartbeats: ($heartbeats | split("\n") | map(select(. != ""))), backupResult: $backupResult} + $archiver')
result "$output"
