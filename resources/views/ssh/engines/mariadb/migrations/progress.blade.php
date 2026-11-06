state={{ $stateDir }}
in_db() { "$@" </dev/null; }
if in_db test -f "$state/copy.exit"; then
    printf 'copy_exit=%s\n' "$(in_db cat "$state/copy.exit")"
    printf 'position=%s\n' "$(in_db cat "$state/position" 2>/dev/null)"
elif in_db test -f "$state/copy.sh"; then
    in_db pgrep -f "$state/copy.sh" > /dev/null && echo "copy=running" || echo "copy=lost"
fi
printf 'log=%s\n' "$(in_db tail -n 2 "$state/copy.log" 2>/dev/null | tr '\n' ' ')"
filters=$(in_db mariadb -N -B -e "SELECT CONCAT(@@@migration.replicate_wild_do_table, @@@migration.replicate_wild_ignore_table)" 2>/dev/null || true)
if [ -z "$filters" ] && [ -n "$(in_db mariadb -N -B -e "SHOW SLAVE 'migration' STATUS" 2>/dev/null)" ]; then
    echo '{{ $refilter }}' | base64 -d | mariadb > /dev/null 2>&1 || true
fi
in_db mariadb -e "SHOW SLAVE 'migration' STATUS\G" 2>/dev/null | sed -n 's/^ *\(Slave_IO_Running\|Slave_SQL_Running\|Seconds_Behind_Master\|Last_IO_Error\|Last_SQL_Error\): */\1=/p' || true
