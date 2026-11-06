before_replay() {
    [ -z "$(sql "{!! $gtidQuery !!}")" ] || return 0
    purged=$(sed '1s/^[^\t]*\t[^\t]*\t//' "$work/binlog-info" | tr -d '\n ')
    [ -z "$purged" ] || sql "RESET BINARY LOGS AND GTIDS; SET GLOBAL gtid_purged = '$purged'" > /dev/null
}
replay_binlog() {
    TZ=UTC {{ $binlogTool }} --stop-datetime='{{ $target }}' "$1" | timeout 21600 {{ $client }} --binary-mode
}
after_replay() {
    sql "SET sql_log_bin = 0; DROP SCHEMA IF EXISTS mysql_innodb_cluster_metadata; SET sql_log_bin = 1" > /dev/null
    for account in $(sql "SELECT CONCAT(QUOTE(user), '@', QUOTE(host)) FROM mysql.user WHERE user LIKE 'mysql\\_innodb\\_cluster\\_%' OR user LIKE 'mysql\\_router%'"); do
        sql "DROP USER $account" > /dev/null
    done
    if [ "$(sql "SELECT COUNT(*) FROM information_schema.plugins WHERE plugin_name = 'group_replication'")" != "0" ]; then
        sql "UNINSTALL PLUGIN group_replication" > /dev/null
    fi
    sql "RESET REPLICA ALL" > /dev/null
    sql "RESET PERSIST" > /dev/null
}
