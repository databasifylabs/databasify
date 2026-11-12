@include('engines.postgres._psql')
@include('engines.postgres._backrest')
CONF=/srv/postgres/conf/pgbackrest.conf
problem_only() {
    result "$(jq -cn --arg problem "$1" '{backups: 0, earliest: null, latest: null, lastBackup: null, problem: $problem}')"
    exit 0
}
if [ ! -e "$CONF" ]; then
    problem_only 'point-in-time recovery is not configured on this node'
fi
info=$(backrest "$CONF" info --output=json 2>&1) || problem_only "pgBackRest could not read the archive: pgbackrest --config=$CONF: $(tail -n 5 <<< "$info")"
if ! jq -e 'type == "array" and length > 0' <<< "$info" > /dev/null 2>&1; then
    problem_only 'pgBackRest could not read the archive: unreadable pgBackRest info'
fi
stops=$(jq -r '[.[0].backup[]?.timestamp.stop] | sort | .[]' <<< "$info")
backups=$(grep -c . <<< "$stops" || true)
earliest=$(head -n 1 <<< "$stops")
last_backup=$(tail -n 1 <<< "$stops")
code=$(jq -r '.[0].status.code' <<< "$info")
message=$(jq -r '.[0].status.message' <<< "$info")

beats_file=/srv/postgres/archive/state/heartbeats
beats=$(grep . "$beats_file" 2> /dev/null || true)
pg_try postgres <<'SQL'
SELECT floor(extract(epoch FROM now()))::bigint, pg_walfile_name(pg_current_wal_insert_lsn())
SQL
if [ -z "$PG_ERROR" ] && [ -n "$PG_OUTPUT" ]; then
    beats=$(printf '%s\n%s' "$beats" "${PG_OUTPUT//|/ }" | grep .)
fi
beats=$(tail -n 50 <<< "$beats")
printf '%s\n' "$beats" > "$beats_file"
chmod 600 "$beats_file"

pg_try postgres <<'SQL'
SELECT COALESCE(last_archived_wal, '') FROM pg_stat_archiver
SQL
archived=$PG_OUTPUT
latest=''
if [[ $archived =~ ^[0-9A-F]{24} ]] && [ -n "$earliest" ]; then
    latest=$(awk -v since="$earliest" -v segment="${archived:8:16}" -v whole="$([ ${#archived} -eq 24 ] && echo 1 || echo 0)" '
        NF >= 2 && length($2) >= 24 && $1 + 0 >= since + 0 {
            rest = substr($2, 9) ""
            if (rest < (segment "") || (whole == 1 && rest == (segment ""))) {
                if (best == "" || $1 + 0 > best + 0) { best = $1 }
            }
        }
        END { print best }' <<< "$beats")
fi

problem=''
if [ "$code" = 1 ]; then
    backrest "$CONF" stanza-create > /dev/null 2>&1 || true
else
    pg_try postgres <<'SQL'
SELECT CASE WHEN last_failed_time IS NOT NULL AND (last_archived_time IS NULL OR last_failed_time > last_archived_time) THEN 'WAL ' || last_failed_wal || ' failed to archive at ' || to_char(last_failed_time AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC.' ELSE '' END FROM pg_stat_archiver
SQL
    problem=$PG_OUTPUT
    if [ -z "$problem" ] && [ "$code" != 0 ] && [ "$code" != 2 ] && [ "$code" != 6 ]; then
        problem="pgBackRest reports: $message"
    fi
fi
unit_result=$(databasify ctl show -p Result --value databasify-postgres-backup 2> /dev/null || true)
backup_failed=false
if [ -n "$unit_result" ] && [ "$unit_result" != success ]; then
    backup_failed=true
    if [ -z "$problem" ]; then
        problem="The last base backup stopped with $unit_result, see the databasify-postgres-backup log."
    fi
fi
if [ -n "$last_backup" ] && { [ -z "$latest" ] || [ "$latest" -lt "$last_backup" ]; }; then
    latest=$last_backup
fi
if [ "$BACK_UP_NOW" = true ] || { [ "$backups" = 0 ] && [ "$backup_failed" = false ]; }; then
    databasify ctl start --no-block databasify-postgres-backup > /dev/null 2>&1 || true
fi
moment() {
    if [ -z "$1" ]; then
        echo null
    else
        jq -cn --arg at "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ)" '$at'
    fi
}
result "$(jq -cn --argjson backups "${backups:-0}" --argjson earliest "$(moment "$earliest")" --argjson latest "$(moment "$latest")" --argjson last "$(moment "$last_backup")" --arg problem "$problem" '{backups: $backups, earliest: $earliest, latest: $latest, lastBackup: $last, problem: (if $problem == "" then null else $problem end)}')"
