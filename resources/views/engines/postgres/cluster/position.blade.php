@include('engines.postgres._psql')
if unit_active databasify-patroni || unit_active databasify-postgres; then
    pg_try postgres <<'SQL'
SELECT pg_wal_lsn_diff(CASE WHEN pg_is_in_recovery() THEN GREATEST(pg_last_wal_receive_lsn(), pg_last_wal_replay_lsn()) ELSE pg_current_wal_lsn() END, '0/0')::bigint
SQL
    if [ -z "$PG_ERROR" ]; then
        result "$(jq -cn --argjson position "${PG_OUTPUT:-0}" '{position: $position}')"
        exit 0
    fi
fi
best=''
while IFS=: read -r label value; do
    case "$label" in
        'Latest checkpoint location' | 'Minimum recovery ending location') ;;
        *) continue ;;
    esac
    value=$(echo "$value" | tr -d '[:space:]')
    high=${value%%/*}
    low=${value#*/}
    if [[ $value == */* ]] && [[ $high =~ ^[0-9A-Fa-f]+$ ]] && [[ $low =~ ^[0-9A-Fa-f]+$ ]]; then
        bytes=$(( (16#$high << 32) + 16#$low ))
        if [ -z "$best" ] || [ "$bytes" -gt "$best" ]; then
            best=$bytes
        fi
    fi
done < <(HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 120 "/usr/lib/postgresql/$LINE/bin/pg_controldata" /srv/postgres/data 2> /dev/null || true)
result "$(jq -cn --arg best "$best" '{position: (if $best == "" then null else ($best | tonumber) end)}')"
