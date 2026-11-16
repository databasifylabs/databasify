@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rewrite')
@include('engines.mariadb._state')
@include('engines.mariadb._galera')
if read_state; then
    if [ "$STATE_CLUSTER" = Primary ]; then
        databasify_fail busy 'this node is already in a primary component'
    fi
    my '' > /dev/null <<< "SET GLOBAL wsrep_provider_options = 'pc.bootstrap=YES'"
else
    allow_bootstrap
    mark_new_cluster
    unit_do daemon-reload
    unit_do start mariadb-server
fi
wait_until 21600 2 synced_now || databasify_fail internal 'the server was not ready within 6h0m0s'
clear_new_cluster
echo "Bootstrapped the cluster from this node."
