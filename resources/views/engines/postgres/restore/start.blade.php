@include('engines.postgres._psql')
@include('engines.postgres._files')
@include('engines.postgres._backrest')
@php($bin = "/usr/lib/postgresql/{$line}/bin")
RESTORE_CONF=/srv/postgres/conf/pgbackrest-restore.conf
printf '%s' "$RESTORE_CONFIG" | write_file "$RESTORE_CONF" 600 || true
trap 'rm -f "$RESTORE_CONF"' EXIT

echo 'Stopping…'
@foreach ($units as $unit)
databasify ctl stop {!! $sh->arg($unit) !!} > /dev/null 2>&1 || true
@endforeach
find /srv/postgres/data -mindepth 1 -maxdepth 1 -exec rm -rf {} +

echo 'Restoring…'
output=$(backrest "$RESTORE_CONF" --type=time {!! $sh->arg("--target={$target}+00") !!} --target-action=promote "--recovery-option=restore_command=pgbackrest --config=$RESTORE_CONF --stanza=main archive-get %f \"%p\"" restore 2>&1) \
    || fail_task "pgbackrest --config=$RESTORE_CONF: $(tail -n 5 <<< "$output")"

raised=()
extra=''
control=$(as_postgres timeout 120 {!! $sh->arg("{$bin}/pg_controldata") !!} /srv/postgres/data 2>&1) \
    || fail_task {!! $sh->arg("{$bin}/pg_controldata /srv/postgres/data: ") !!}"$(tail -n 5 <<< "$control")"
while IFS=: read -r label value; do
    case "$(echo "$label" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')" in
        'max_connections setting') name=max_connections ;;
        'max_worker_processes setting') name=max_worker_processes ;;
        'max_wal_senders setting') name=max_wal_senders ;;
        'max_prepared_xacts setting') name=max_prepared_transactions ;;
        'max_locks_per_xact setting') name=max_locks_per_transaction ;;
        *) continue ;;
    esac
    current=$(as_postgres timeout 120 {!! $sh->arg("{$bin}/postgres") !!} -D /srv/postgres/data -c config_file=/srv/postgres/conf/postgresql.conf -C "$name" 2>&1) \
        || fail_task {!! $sh->arg("{$bin}/postgres -D: ") !!}"$(tail -n 5 <<< "$current")"
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

echo 'Replaying…'
databasify ctl reset-failed databasify-postgres > /dev/null 2>&1 || true
databasify ctl start databasify-postgres > /dev/null 2>&1 || fail_task 'could not start databasify-postgres'
promoted() {
    pg_try postgres <<< 'SELECT pg_is_in_recovery()'
    [ -z "$PG_ERROR" ] && [ "$PG_OUTPUT" = f ]
}
wait_until 21000 5 promoted || fail_task {!! $sh->arg("recovery did not reach {$target} UTC") !!}

echo 'Resetting…'
for setting in restore_command recovery_target_time recovery_target_action recovery_target_timeline "${raised[@]}"; do
    pg postgres > /dev/null <<< "ALTER SYSTEM RESET $setting"
done
pg postgres > /dev/null <<< 'SELECT pg_reload_conf()'
