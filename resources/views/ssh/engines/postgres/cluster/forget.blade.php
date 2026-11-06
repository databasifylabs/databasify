@include('ssh.engines.postgres.helpers')
ip={{ explode(':', $instance)[0] }}
if ! etcd_ctl endpoint health > /dev/null 2>&1; then
    echo "etcd lost its majority without $ip, clustering is switched off next"
    exit 0
fi
member=$(etcd_ctl member list | awk -F ', ' -v peer="http://$ip:{{ $etcdPeerPort }}" '$4 == peer { print $1 }')
if [ -n "$member" ]; then
    etcd_ctl member remove "$member" > /dev/null
    echo "$ip removed from etcd"
else
    echo "$ip holds no etcd membership"
fi
