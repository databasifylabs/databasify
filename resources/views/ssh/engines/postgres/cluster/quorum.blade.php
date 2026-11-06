@include('ssh.engines.postgres.helpers')
hosts=$({ etcd_ctl member list --consistency=s -w json 2> /dev/null || true; } | jq -r '.members[]? | select(.isLearner | not) | .peerURLs[0] // empty' 2> /dev/null | sed 's#^http://\(.*\):[0-9]*$#\1:{{ $etcdClientPort }}#' | tr '\n' ' ' || true)
if [ -z "${hosts// /}" ]; then
    hosts=$(sed -n "s/^  hosts: '\(.*\)'$/\1/p" {{ $directory }}/conf/patroni.yml 2> /dev/null | tr ',' ' ')
fi
total=0
online=0
for host in $hosts; do
    total=$((total + 1))
    patroni_up=$(timeout 10 curl -s --max-time 5 "http://${host%:*}:{{ $patroniPort }}/patroni" < /dev/null 2> /dev/null | jq -r '.state // empty' 2> /dev/null || true)
    case "$patroni_up" in
        running | streaming) timeout 15 etcdctl --endpoints="http://$host" endpoint health < /dev/null > /dev/null 2>&1 && online=$((online + 1)) ;;
    esac
done
echo "total=$total"
echo "online=$online"
