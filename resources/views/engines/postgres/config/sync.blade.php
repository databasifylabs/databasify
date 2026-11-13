@include('engines.postgres._psql')
if ! clustered; then
    echo "Synced the settings."
    exit 0
fi

wanted() {
    sed -n "s/^[[:space:]]*$1: //p" /srv/postgres/conf/dcs.yml 2> /dev/null | head -n 1 | sed -e "s/^'\(.*\)'.*$/\1/" -e 's/[[:space:]]*#.*$//'
}

lowers=false
for name in max_connections max_locks_per_transaction max_prepared_transactions max_wal_senders max_worker_processes; do
    running=$(DATABASIFY_VAR_name="$name" pg postgres <<'SQL'
SELECT setting, boot_val FROM pg_settings WHERE name = :'name'
SQL
)
    IFS='|' read -r setting boot_val <<< "$running"
    [ -z "$boot_val" ] && continue
    target=$(wanted "$name")
    target=${target:-$boot_val}
    if [ "${target:-0}" -lt "${setting:-0}" ] 2> /dev/null; then
        lowers=true
        break
    fi
done

patronictl_run edit-config --force --replace /srv/postgres/conf/dcs.yml > /dev/null
pending_restart() {
    [ "$(patroni_state | jq -r '.pending_restart // false')" = true ]
}
leading() {
    [ "$(patroni_state | jq -r '(.role // "") + " " + (.state // "")')" = "primary running" ] || [ "$(patroni_state | jq -r '(.role // "") + " " + (.state // "")')" = "master running" ]
}
if [ "$lowers" != true ] || ! wait_until 60 2 pending_restart; then
    echo "The settings need no restart."
    exit 0
fi
patronictl_run restart main --role primary --force > /dev/null
wait_until 300 2 leading || true
echo "Synced the settings and restarted the primary."
