@include('ssh.engines.mysql.helpers')
count_in_metadata() { count=$(msql "$1") || { echo "could not read the cluster metadata"; exit 1; }; }
listed() {
    count_in_metadata "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'mysql_innodb_cluster_metadata' AND table_name = 'instances'"
    [ "$count" = 1 ] || return 1
    count_in_metadata "SELECT COUNT(*) FROM mysql_innodb_cluster_metadata.instances WHERE address = '{{ $instance }}'"
    [ "$count" = 1 ]
}
if listed; then
    cluster_admin "dba.get_cluster().remove_instance('{{ $instance }}', {'force': True})" 120 > /dev/null || true
fi
if listed; then
    echo "{{ $instance }} is still in the cluster metadata"
    exit 1
fi
echo "{{ $instance }} removed from the cluster"
