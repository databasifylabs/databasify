state={{ $stateDir }}
in_db() { "$@" </dev/null; }
databasify ctl stop mariadb-migration > /dev/null 2>&1 || true
in_db mariadb -e "STOP SLAVE 'migration'; RESET SLAVE 'migration' ALL" 2>/dev/null || true
in_db rm -rf "$state"
echo "migration stopped and cleaned up"
