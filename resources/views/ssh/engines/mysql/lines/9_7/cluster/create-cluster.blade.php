if ! in_cluster; then
    cluster_admin "dba.create_cluster('main', {'communicationStack': 'MYSQL', 'memberSslMode': 'REQUIRED', 'expelTimeout': 0})" > /dev/null
    echo "created the InnoDB Cluster on this node"
fi
