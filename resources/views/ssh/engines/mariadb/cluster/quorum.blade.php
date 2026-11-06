@include('ssh.engines.mariadb.helpers')
expected=$(msql 'SELECT @@@wsrep_cluster_address' | sed 's|^gcomm://||' | tr ',' '\n' | grep -c . || true)
echo "total=${expected:-0}"
[ "$(global_status WSREP_CLUSTER_STATUS || true)" = "Primary" ] && echo "online=${expected:-0}" || echo "online=0"
