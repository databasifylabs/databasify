@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
if in_galera && wsrep_serving; then
    me=$(msql 'SELECT @@@wsrep_node_address')
    want=1
    [ "$(chosen_writer)" = "$me" ] && want=0
    [ "$(msql 'SELECT @@@read_only')" = "$want" ] || msql "SET GLOBAL read_only = $want" || true
    if [ "$want" = "0" ] && [ "$(recorded_writer)" != "$me" ]; then
        msql "REPLACE INTO databasify.writer VALUES (1, '$me')" || true
    fi
elif in_galera && [ "$(global_status WSREP_CLUSTER_STATUS)" != "Primary" ]; then
    [ "$(msql 'SELECT @@@read_only')" = "1" ] || msql "SET GLOBAL read_only = 1" || true
elif ! in_galera && [ "$(msql 'SELECT @@@read_only')" = "1" ] && [ "$(replication_source)" != '{{ $primary }}' ]; then
    msql "STOP SLAVE; CHANGE MASTER TO MASTER_HOST = '{{ $primary }}'; START SLAVE" || true
    wait_for 30 replicating || true
fi
own_state
msql "SELECT VERSION()" | sed 's/-MariaDB.*//; s/^/version:/' || true
