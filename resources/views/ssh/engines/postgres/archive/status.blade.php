@include('ssh.engines.postgres.helpers')
@include('ssh.databases.recovery.settings-check', ['path' => $archiveConfig])
errors=$(mktemp)
trap 'rm -f "$errors"' EXIT
info=$(as_postgres pgbackrest --config={{ $archiveConfig }} --stanza={{ $stanza }} --log-level-console=off info --output=json 2> "$errors") || {
    echo "problem=pgBackRest could not read the archive: $(tail -n 1 "$errors")"
    exit 0
}
code=$(printf '%s' "$info" | jq -r '.[0].status.code // 99')
backups=$(printf '%s' "$info" | jq '[.[0].backup[]?] | length')
earliest=$(printf '%s' "$info" | jq -r '[.[0].backup[]?.timestamp.stop] | min // empty')
last_backup=$(printf '%s' "$info" | jq -r '[.[0].backup[]?.timestamp.stop] | max // empty')
heartbeats={{ $archiveWork }}/state/heartbeats
install -d -m 700 {{ $archiveWork }}/state
beat=$(pg "SELECT floor(extract(epoch FROM now()))::bigint, txid_current()" | cut -d '|' -f 1 || true)
if [ -n "$beat" ]; then
    printf '%s %s\n' "$beat" "$(pg 'SELECT pg_walfile_name(pg_current_wal_insert_lsn())')" >> "$heartbeats"
    tail -n 50 "$heartbeats" > "$heartbeats.kept" && mv "$heartbeats.kept" "$heartbeats"
fi
archived_wal=$(pg "SELECT COALESCE(last_archived_wal, '') FROM pg_stat_archiver" || true)
latest=$(awk -v archived="$archived_wal" -v since="${earliest:-}" '
    BEGIN { segment = substr(archived, 9, 16); named = length(archived) >= 24 && substr(archived, 1, 24) ~ /^[0-9A-F]+$/; whole = named && length(archived) == 24 }
    named && since != "" && $1 >= since && (substr($2, 9) < segment || (whole && substr($2, 9) == segment)) && $1 > latest { latest = $1 }
    END { print latest }' "$heartbeats" 2> /dev/null || true)
problem=""
if [ "$code" = "1" ]; then
    as_postgres pgbackrest --config={{ $archiveConfig }} --stanza={{ $stanza }} --log-level-console=off stanza-create > /dev/null 2>&1 || true
else
    problem=$(pg "SELECT CASE WHEN last_failed_time IS NOT NULL AND (last_archived_time IS NULL OR last_failed_time > last_archived_time) THEN 'WAL ' || last_failed_wal || ' failed to archive at ' || to_char(last_failed_time AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC.' ELSE '' END FROM pg_stat_archiver" || true)
fi
dropped=$(grep -h 'dropped WAL file' {{ $archiveWork }}/log/*archive-push*.log 2> /dev/null | tail -n 1 | cut -c 1-19 || true)
if [ -z "$problem" ] && [ -n "$dropped" ] && [ -n "$last_backup" ] && [ "$(date -d "$dropped" +%s 2> /dev/null || echo 0)" -gt "$last_backup" ]; then
    problem="pgBackRest dropped WAL at $dropped because the bucket was unreachable and the local queue was full. Restores to times after the last base backup may fail until the next one."
fi
case "$code" in
    0 | 1 | 2 | 6) ;;
    *) [ -n "$problem" ] || problem="pgBackRest reports: $(printf '%s' "$info" | jq -r '.[0].status.message // "unknown error"')" ;;
esac
@include('ssh.databases.recovery.report')
