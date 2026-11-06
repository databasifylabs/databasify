@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
if server_active && in_galera; then
    msql "SET GLOBAL wsrep_provider_pc_bootstrap = ON" || msql "SET GLOBAL wsrep_provider_options = 'pc.bootstrap=YES'"
else
    stop_server
    sed -i 's/^safe_to_bootstrap: *0/safe_to_bootstrap: 1/' "$dir/data/grastate.dat"
    galera_new_cluster
fi
synced() { in_galera && wsrep_synced; }
wait_for 300 synced
msql "REPLACE INTO databasify.writer VALUES (1, '$(msql 'SELECT @@@wsrep_node_address')')"
msql "SET GLOBAL read_only = 0"
echo "Galera primary component restored on {{ $instance }}"
