@include('engines.postgres._psql')
offline() {
    result '{"role":"offline","version":null}'
    exit 0
}

if unit_active databasify-patroni; then
    patroni_role=$(curl -fsS --max-time 5 http://127.0.0.1:8008/patroni 2>/dev/null | jq -r '.role // ""' || true)
    case "$patroni_role" in
        '') offline ;;
        master | primary) role=primary ;;
        replica | standby_leader) role=replica ;;
        *) role=unknown ;;
    esac
else
    pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
    if [ "$PG_ERROR" = unavailable ]; then
        offline
    elif [ -n "$PG_ERROR" ]; then
        databasify_fail "$PG_ERROR" "$PG_ERROR_TEXT"
    fi
    role=primary
    if [ "$PG_OUTPUT" = t ]; then
        role=replica
    fi
fi

pg_try postgres <<'SQL'
SHOW server_version
SQL
if [ "$PG_ERROR" = unavailable ]; then
    offline
elif [ -n "$PG_ERROR" ]; then
    databasify_fail "$PG_ERROR" "$PG_ERROR_TEXT"
fi
result "$(jq -cn --arg role "$role" --arg version "${PG_OUTPUT%% *}" '{role: $role, version: $version}')"
