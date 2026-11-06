state={{ $stateDir }}
in_mysql() { timeout 60 "$@" </dev/null; }
copy_running() { databasify ctl is-active --quiet databasify-mysql-migration; }
stop_copy() { databasify ctl stop databasify-mysql-migration > /dev/null 2>&1 || true; databasify ctl reset-failed databasify-mysql-migration > /dev/null 2>&1 || true; }
migration_status() {
    timeout 60 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf --vertical -e "SHOW REPLICA STATUS FOR CHANNEL 'migration'" </dev/null \
        | awk -F ': ' '/^ *(Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error):/ { gsub(/^ +/, "", $1); print $1 "=" $2 }'
}
