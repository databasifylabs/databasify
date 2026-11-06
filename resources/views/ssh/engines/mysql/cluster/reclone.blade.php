@if ($readOnly)
cluster_admin_at "$peer" "cluster = dba.get_cluster(); cluster.remove_instance('$instance', {'force': True}); cluster.add_replica_instance('$instance', {'recoveryMethod': 'clone'})" 21600 > /dev/null
@else
cluster_admin_at "$peer" "cluster = dba.get_cluster(); cluster.remove_instance('$instance', {'force': True}); cluster.add_instance('$instance', {'recoveryMethod': 'clone', 'localAddress': '{{ $privateIp }}:{{ $groupPort }}'$(group_allowlist_option)})" 21600 > /dev/null
@endif
