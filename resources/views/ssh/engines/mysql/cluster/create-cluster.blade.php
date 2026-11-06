if ! in_cluster; then
    cluster_admin "dba.create_cluster('main', {'communicationStack': 'XCOM', 'memberSslMode': 'REQUIRED', 'expelTimeout': 0, 'localAddress': '{{ $privateIp }}:{{ $groupPort }}'$(group_allowlist_option)})" > /dev/null
    echo "created the InnoDB Cluster on this node"
fi
