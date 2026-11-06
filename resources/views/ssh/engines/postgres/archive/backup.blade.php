set -euo pipefail
backrest() { pgbackrest --config={{ $archiveConfig }} --stanza={{ $stanza }} "$@"; }
recovering=$(psql -X -A -t -h {{ $socketDirectory }} -p {{ $clientPort }} -d postgres -c 'SELECT pg_is_in_recovery()' 2> /dev/null || echo unknown)
if [ "$recovering" != "f" ]; then
    echo "not the primary, skipping"
    exit 0
fi
backrest stanza-create
last_full=$(backrest --log-level-console=off info --output=json | jq '[.[0].backup[]? | select(.type == "full") | .timestamp.stop] | max // 0')
type=diff
if [ $(( $(date +%s) - last_full )) -ge {{ 6 * 86400 }} ]; then
    type=full
fi
echo "taking a $type backup"
backrest --type="$type" backup
