@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
galera_new_cluster
synced() { in_galera && wsrep_synced; }
wait_for 300 synced
msql "SET GLOBAL read_only = 0"
echo "this node now leads a Galera cluster"
