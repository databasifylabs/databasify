@include('ssh.engines.mysql.helpers')
if [ "$(msql "SELECT COUNT(*) FROM mysql_innodb_cluster_metadata.instances WHERE address = '{{ $instance }}'")" = "1" ]; then
    echo "{{ $instance }} is already in the cluster"
    exit 0
fi
@if ($readOnly)
cluster_admin "dba.get_cluster().add_replica_instance('{{ $instance }}', {'recoveryMethod': 'clone'@if ($donorInstance ?? null), 'cloneDonor': '{{ $donorInstance }}'@endif})" 21600 > /dev/null
echo "{{ $instance }} replicates from the cluster"
@else
cluster_admin "dba.get_cluster().add_instance('{{ $instance }}', {'recoveryMethod': 'clone', 'localAddress': '{{ $node }}:{{ $groupPort }}'$(group_allowlist_option)})" 21600 > /dev/null
echo "{{ $instance }} joined the cluster"
@endif
