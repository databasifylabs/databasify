msql() { timeout 60 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf -NB -e "$1" </dev/null 2>/dev/null; }
msql_from() { base64 -d | timeout 300 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf -NB; }
cluster_admin_at() { timeout "${3:-300}" mysqlsh --no-defaults --no-wizard --py --quiet-start=2 -e "shell.connect({'scheme': 'mysql', 'user': 'clusteradmin', 'host': '$1', 'port': {{ $clientPort }}, 'password': open('{{ $directory }}/conf/clusteradmin.pass').read().strip()}); $2"; }
cluster_admin() { cluster_admin_at 127.0.0.1 "$1" "${2:-300}"; }
listed_at() { [ "$(cluster_admin_at "$1" "print(session.run_sql(\"SELECT COUNT(*) FROM mysql_innodb_cluster_metadata.instances WHERE address = '$2'\").fetch_one()[0])" 60 2> /dev/null | tail -n 1)" = "1" ]; }
config_fingerprint() { { cat {{ $configFile }}; jq -c '.processes' {{ $directory }}/conf/processes.json || true; } 2> /dev/null | sha256sum | cut -d ' ' -f 1; }
record_running_config() { config_fingerprint > {{ $directory }}/.running-config; }
runs_current_config() { databasify ctl is-active --quiet mysql && [ -f {{ $directory }}/data/auto.cnf ] && [ "$(cat {{ $directory }}/.running-config 2> /dev/null)" = "$(config_fingerprint)" ]; }
group_allowlist_option() { sed -n "s/^loose-group_replication_ip_allowlist = \(.*\)$/, 'ipAllowlist': '\1'/p" {{ $configFile }} 2> /dev/null | tail -n 1; }
in_cluster() { [ "$(msql "SELECT COUNT(*) FROM mysql_innodb_cluster_metadata.clusters" || echo 0)" != "0" ]; }
group_seeds() { msql "SELECT COALESCE(GROUP_CONCAT(addresses->>'$.grLocal' ORDER BY address), '') FROM mysql_innodb_cluster_metadata.instances WHERE instance_type = 'group-member' AND address <> CONCAT(@@@report_host, ':', @@@port)"; }
copied_identity() { [ "$(msql "SELECT COUNT(*) FROM mysql_innodb_cluster_metadata.instances WHERE address = CONCAT(@@@report_host, ':', @@@port) AND mysql_server_uuid <> @@@server_uuid" || echo 0)" != "0" ]; }
reachable_peer() {
    for peer in $(msql "SELECT SUBSTRING_INDEX(address, ':', 1) FROM mysql_innodb_cluster_metadata.instances WHERE address <> CONCAT(@@@report_host, ':', @@@port)"); do
        cluster_admin_at "$peer" "dba.get_cluster()" 60 > /dev/null 2>&1 && { echo "$peer"; return 0; }
    done
    return 1
}
refresh_group_seeds() {
    seeds=$(group_seeds)
    [ -n "$seeds" ] || return 0
    msql "SET PERSIST group_replication_group_seeds = '$seeds'" > /dev/null
}
own_member() { msql "SELECT MEMBER_ROLE, MEMBER_STATE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@@server_uuid AND MEMBER_STATE <> 'OFFLINE'"; }
in_the_group() { own_state | grep -q -E 'ONLINE|RECOVERING'; }
own_state() {
    member=$(own_member)
    [ -n "$member" ] && { echo "$member"; return 0; }
    [ "$(msql "SELECT SERVICE_STATE FROM performance_schema.replication_connection_status WHERE CHANNEL_NAME NOT LIKE 'group_replication%' AND CHANNEL_NAME <> 'migration' LIMIT 1")" = "ON" ] && { echo "READ_REPLICA ONLINE"; return 0; }
@if (! ($clustered ?? false))
    [ "$(msql 'SELECT @@@super_read_only')" != "0" ] || echo "PRIMARY ONLINE"
@endif
}
