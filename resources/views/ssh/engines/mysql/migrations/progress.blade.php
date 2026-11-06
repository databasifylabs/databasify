@include('ssh.engines.mysql.migrations.helpers')
if in_mysql test -f "$state/copy.exit"; then
    printf 'copy_exit=%s\n' "$(in_mysql cat "$state/copy.exit")"
    in_mysql grep '^POSITION ' "$state/copy.log" | tail -n 1 | sed 's/^POSITION /position=/' || true
    printf 'log=%s\n' "$(in_mysql tail -n 3 "$state/copy.log" | tr '\n' ' ')"
elif in_mysql test -f "$state/migration.py"; then
    copy_running && echo "copy=running" || echo "copy=lost"
    printf 'log=%s\n' "$(in_mysql tail -n 1 "$state/copy.log" 2>/dev/null | tr '\n' ' ')"
else
    filters=$(timeout 60 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf -N -B -e "SELECT COUNT(*) FROM performance_schema.replication_applier_filters WHERE channel_name = 'migration'" </dev/null 2> /dev/null || true)
    if [ "$filters" = 0 ]; then
        echo '{{ $refilter }}' | base64 -d | timeout 60 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf </dev/null > /dev/null 2>&1 || true
    fi
    migration_status || true
fi
