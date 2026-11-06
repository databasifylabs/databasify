msql() { timeout 60 mariadb -N -B -e "$1" </dev/null 2>/dev/null; }
msql_from() { base64 -d | timeout 300 mariadb -N -B; }
global_status() { msql "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = '$1'"; }
in_galera() { [ "$(msql 'SELECT @@@wsrep_on')" = "1" ]; }
wsrep_synced() { [ "$(global_status WSREP_LOCAL_STATE)" = "4" ] && [ "$(global_status WSREP_CLUSTER_STATUS)" = "Primary" ] && [ "$(global_status WSREP_READY)" = "ON" ]; }
wsrep_serving() { grep -qx -E "[24]" <<< "$(global_status WSREP_LOCAL_STATE)" && [ "$(global_status WSREP_CLUSTER_STATUS)" = "Primary" ] && [ "$(global_status WSREP_READY)" = "ON" ]; }
recorded_writer() { msql "SELECT ip FROM databasify.writer WHERE id = 1" || true; }
chosen_writer() {
    table=$(recorded_writer)
    members=$(global_status WSREP_INCOMING_ADDRESSES | tr ',' '\n' | sed 's/:.*//' | grep -v '^$' | sort -t . -k1,1n -k2,2n -k3,3n -k4,4n)
    if [ -n "$table" ] && grep -qx "$table" <<< "$members"; then echo "$table"; else head -n 1 <<< "$members"; fi
}
slave_status_of() { timeout 60 mariadb -e "SHOW SLAVE '$1' STATUS\\G" </dev/null 2>/dev/null | sed -n "s/^ *$2: //p"; }
replication_source() { slave_status_of '' Master_Host; }
replicating_on() { [ "$(slave_status_of "$1" Slave_IO_Running)" = "Yes" ] && [ "$(slave_status_of "$1" Slave_SQL_Running)" = "Yes" ]; }
replicating() { replicating_on ''; }
own_state() {
    if in_galera; then
        wsrep_serving || { echo "UNKNOWN OFFLINE"; return 0; }
        [ "$(chosen_writer)" = "$(msql 'SELECT @@@wsrep_node_address')" ] && echo "WRITER ONLINE" || echo "MEMBER ONLINE"
        return 0
    fi
    if [ "$(msql 'SELECT @@@read_only')" = "0" ]; then
        echo "WRITER ONLINE"
        return 0
    fi
    replicating && echo "READ_REPLICA ONLINE" || echo "UNKNOWN OFFLINE"
}
server_active() { databasify ctl is-active --quiet mariadb; }
@include('ssh.engines.mariadb.lifecycle.fingerprint')
runs_current_config() { server_active && [ "$(cat {{ $runningConfig }} 2> /dev/null)" = "$(config_fingerprint)" ] && own_state | grep -q 'ONLINE'; }
starting() { server_active && ! msql "SELECT 1" > /dev/null; }
settled() { ! starting; }
restart_unless_current() {
    wait_for 21000 settled
    if runs_current_config; then
        echo "mariadb already runs this configuration, no restart needed"
        return 0
    fi
    databasify ctl enable mariadb > /dev/null 2>&1
    databasify ctl restart mariadb
}
stop_server() {
    timeout 60 databasify ctl stop mariadb > /dev/null 2>&1 && return 0
    echo "mariadb ignored SIGTERM while waiting for a primary component, killing it"
    databasify ctl kill --signal=KILL mariadb
    databasify ctl stop mariadb
}
galera_new_cluster() {
    stop_server
    install -m 600 -o mysql -g mysql /dev/null {{ $newClusterFlag }}
    databasify ctl enable mariadb > /dev/null 2>&1
    databasify ctl start mariadb
}
