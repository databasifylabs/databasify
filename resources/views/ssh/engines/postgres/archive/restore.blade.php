@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
trap 'rm -f {{ $restoreConfig }}' EXIT
echo '{{ base64_encode($source) }}' | base64 -d | put_file '{{ $restoreConfig }}' 600 postgres
for process in $processes; do
    databasify ctl stop "$process" > /dev/null 2>&1 || true
done
find "$dir/data" -mindepth 1 -delete
echo "restoring the newest base backup taken before {{ $target }} UTC"
as_postgres pgbackrest --config={{ $restoreConfig }} --stanza={{ $stanza }} --log-level-console=warn \
    --type=time --target='{{ $target }}+00' --target-action=promote \
    --recovery-option='restore_command=pgbackrest --config={{ $restoreConfig }} --stanza={{ $stanza }} archive-get %f "%p"' \
    restore
raised=""
while read -r name value; do
    current=$(as_postgres /usr/lib/postgresql/{{ $line }}/bin/postgres -D "$dir/data" -c config_file="$dir/conf/postgresql.conf" -C "$name")
    if [ "$value" -gt "$current" ]; then
        echo "$name = '$value'" >> "$dir/data/postgresql.auto.conf"
        raised="$raised $name"
        echo "$name raised to $value while replaying, as on the source"
    fi
done < <(as_postgres /usr/lib/postgresql/{{ $line }}/bin/pg_controldata "$dir/data" | sed -n \
    -e 's/^max_connections setting: *\([0-9]*\)$/max_connections \1/p' \
    -e 's/^max_worker_processes setting: *\([0-9]*\)$/max_worker_processes \1/p' \
    -e 's/^max_wal_senders setting: *\([0-9]*\)$/max_wal_senders \1/p' \
    -e 's/^max_prepared_xacts setting: *\([0-9]*\)$/max_prepared_transactions \1/p' \
    -e 's/^max_locks_per_xact setting: *\([0-9]*\)$/max_locks_per_transaction \1/p')
echo "replaying WAL up to {{ $target }} UTC"
databasify ctl reset-failed databasify-postgres > /dev/null 2>&1 || true
databasify ctl start databasify-postgres
promoted() {
    if crash_looping databasify-postgres 2; then
        echo "recovery stopped before reaching {{ $target }} UTC"
        exit 1
    fi
    [ "$(pg 'SELECT pg_is_in_recovery()')" = "f" ]
}
wait_for 21000 promoted
for setting in restore_command recovery_target_time recovery_target_action recovery_target_timeline $raised; do
    pg "ALTER SYSTEM RESET $setting" > /dev/null
done
pg "SELECT pg_reload_conf()" > /dev/null
echo "restored to {{ $target }} UTC"
