@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
databasify ctl daemon-reload
databasify ctl enable mariadb > /dev/null 2>&1
@if ($bootstrapsCluster)
galera_new_cluster
synced() { in_galera && wsrep_synced; }
wait_for 300 synced
@include('ssh.engines.mariadb.cluster.sst-user')
echo "started a new Galera cluster on this node"
@elseif ($readOnly)
if [ ! -d "$dir/data/mysql" ]; then
    socat -u TCP:{{ $donor ?? $primary }}:4444,retry=60,interval=2 - | mbstream -x -C "$dir/data"
    mariadb-backup --prepare --target-dir="$dir/data" > /dev/null 2>&1
    chown -R mysql:mysql "$dir/data"
    gtid=$(awk '{ print $3 }' "$dir/data/mariadb_backup_binlog_info")
    databasify ctl restart mariadb
    up() { msql "SELECT 1" > /dev/null; }
    wait_for 300 up
    msql "SET GLOBAL gtid_slave_pos = '$gtid'"
    msql "CHANGE MASTER TO MASTER_HOST = '{{ $primary }}', MASTER_PORT = {{ $clientPort }}, MASTER_USER = 'replicator', MASTER_PASSWORD = '{{ $secrets['REPLICATION_PASSWORD'] ?? '' }}', MASTER_SSL = 1, MASTER_USE_GTID = slave_pos"
    msql "START SLAVE"
    echo "cloned from {{ $donor ?? $primary }} and replicating from {{ $primary }}"
else
    restart_unless_current
fi
@else
restart_unless_current
@endif
echo "mariadb: $(databasify ctl is-active mariadb || true)"
