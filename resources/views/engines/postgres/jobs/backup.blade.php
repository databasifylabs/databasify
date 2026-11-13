set -euo pipefail
@include('engines.postgres._psql')
@include('engines.postgres._backrest')
CONF=/srv/postgres/conf/pgbackrest.conf
FULL_AFTER_SECONDS=518400
in_recovery=$(pg postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
)
if [ "$in_recovery" = t ]; then
    exit 0
fi
backrest "$CONF" stanza-create
kind=diff
if info=$(backrest "$CONF" info --output=json 2> /dev/null) \
    && last_full=$(jq -er '[.[0].backup[]? | select(.type == "full") | .timestamp.stop] | max // 0' <<< "$info" 2> /dev/null) \
    && [ $(( $(date +%s) - last_full )) -ge "$FULL_AFTER_SECONDS" ]; then
    kind=full
fi
backrest "$CONF" --type="$kind" backup
