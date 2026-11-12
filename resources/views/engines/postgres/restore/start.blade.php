@include('engines.postgres._psql')
@include('engines.postgres._units')
@include('engines.postgres._files')
@include('engines.postgres._backrest')
RESTORE_CONF=/srv/postgres/conf/pgbackrest-restore.conf
printf '%s' "$RESTORE_CONFIG" | write_file "$RESTORE_CONF" 600 || true
trap 'rm -f "$RESTORE_CONF"' EXIT

phase stopping
for name in $(manifest_units 2> /dev/null || true); do
    databasify ctl stop "$name" > /dev/null 2>&1 || true
done
find /srv/postgres/data -mindepth 1 -maxdepth 1 -exec rm -rf {} +

phase restoring
output=$(backrest "$RESTORE_CONF" --type=time "--target=$TARGET+00" --target-action=promote "--recovery-option=restore_command=pgbackrest --config=$RESTORE_CONF --stanza=main archive-get %f \"%p\"" restore 2>&1) \
    || fail_task "pgbackrest --config=$RESTORE_CONF: $(tail -n 5 <<< "$output")"

raised=()
extra=''
control=$(HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 120 "/usr/lib/postgresql/$LINE/bin/pg_controldata" /srv/postgres/data 2>&1) \
    || fail_task "/usr/lib/postgresql/$LINE/bin/pg_controldata /srv/postgres/data: $(tail -n 5 <<< "$control")"
while IFS=: read -r label value; do
    case "$(echo "$label" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')" in
        'max_connections setting') name=max_connections ;;
        'max_worker_processes setting') name=max_worker_processes ;;
        'max_wal_senders setting') name=max_wal_senders ;;
        'max_prepared_xacts setting') name=max_prepared_transactions ;;
        'max_locks_per_xact setting') name=max_locks_per_transaction ;;
        *) continue ;;
    esac
    current=$(HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 120 "/usr/lib/postgresql/$LINE/bin/postgres" -D /srv/postgres/data -c config_file=/srv/postgres/conf/postgresql.conf -C "$name" 2>&1) \
        || fail_task "/usr/lib/postgresql/$LINE/bin/postgres -D: $(tail -n 5 <<< "$current")"
    wanted=$(echo "$value" | tr -d '[:space:]')
    if [ "${wanted:-0}" -gt "$(echo "$current" | tr -d '[:space:]')" ] 2> /dev/null; then
        extra+="$name = '$wanted'"$'\n'
        raised+=("$name")
    fi
done <<< "$control"
if [ -n "$extra" ]; then
    printf '%s' "$extra" >> /srv/postgres/data/postgresql.auto.conf
    chown 64999:64999 /srv/postgres/data/postgresql.auto.conf
    chmod 600 /srv/postgres/data/postgresql.auto.conf
fi

phase replaying
databasify ctl reset-failed databasify-postgres > /dev/null 2>&1 || true
databasify ctl start databasify-postgres > /dev/null 2>&1 || fail_task 'could not start databasify-postgres'
promoted() {
    pg_try postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
}
wait_until 21000 5 promoted || fail_task "recovery did not reach $TARGET UTC"

phase resetting
for setting in restore_command recovery_target_time recovery_target_action recovery_target_timeline "${raised[@]}"; do
    DATABASIFY_VAR_setting="$setting" pg postgres > /dev/null <<'SQL'
SELECT format('ALTER SYSTEM RESET %I', :'setting') \gexec
SQL
done
pg postgres > /dev/null <<'SQL'
SELECT pg_reload_conf()
SQL
