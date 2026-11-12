@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rewrite')
@include('engines.mysql-family._saved')
@include('engines.mariadb._state')
@include('engines.mariadb._galera')
require_saved
if read_state; then
    if [ "$STATE_CLUSTER" = Primary ]; then
        databasify_fail busy 'this node is already in a primary component'
    fi
    my '' > /dev/null <<< "SET GLOBAL wsrep_provider_options = 'pc.bootstrap=YES'"
else
    grastate=$(cat "$ROOT/data/grastate.dat" 2> /dev/null; printf x)
    grastate=${grastate%x}
    printf '%s' "${grastate/safe_to_bootstrap: 0/safe_to_bootstrap: 1}" > "$ROOT/data/grastate.dat"
    chmod 660 "$ROOT/data/grastate.dat"
    mark_new_cluster
    printf '{}' | databasify service reload > /dev/null
    unit_do start mariadb-server
fi
wait_until 21600 2 synced_now || databasify_fail internal 'the server was not ready within 6h0m0s'
clear_new_cluster
result '{}'
