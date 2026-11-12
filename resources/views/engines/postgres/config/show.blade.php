@include('engines.postgres._psql')
if [ ! -f /srv/postgres/conf/request.json ]; then
    databasify_fail busy 'run node configure first'
fi
rows=$(DATABASIFY_VAR_keys="$KEYS" pg postgres <<'SQL'
SELECT lower(s.name), CASE WHEN s.source = 'client' THEN COALESCE((SELECT f.setting FROM pg_file_settings f WHERE lower(f.name) = lower(s.name) AND f.applied ORDER BY f.seqno DESC LIMIT 1), s.boot_val) ELSE current_setting(s.name) END, s.pending_restart FROM pg_settings s WHERE lower(s.name) = ANY (string_to_array(:'keys', ',')) OR s.pending_restart ORDER BY 1
SQL
)
result "$(jq -cn --arg rows "$rows" '{values: ($rows | split("\n") | map(select(. != "") | split("|") | select(length == 3) | {key: .[0], value: .[1], pendingRestart: (.[2] == "t")}))}')"
