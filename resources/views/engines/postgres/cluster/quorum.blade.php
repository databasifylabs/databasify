@include('engines.postgres._psql')
@include('engines.postgres._cluster')
hosts=$(etcd_ctl "$LOCAL_ETCD" member list --consistency=s 2> /dev/null | awk -F ', ' 'NF >= 6 && $6 != "true" { peer = $4; sub(/^http:\/\//, "", peer); sub(/:[0-9]+$/, "", peer); print peer }' || true)
if [ -z "$hosts" ]; then
    hosts=$(patroni_value etcd3.hosts | tr ',' '\n' | sed -n 's/:.*$//p')
fi
total=0
online=0
for host in $hosts; do
    total=$(( total + 1 ))
    state=$(curl -fsS --max-time 5 "http://$host:8008/patroni" 2> /dev/null | jq -r '.state // ""' || true)
    if { [ "$state" = running ] || [ "$state" = streaming ]; } && etcd_ctl "http://$host:2379" endpoint health > /dev/null 2>&1; then
        online=$(( online + 1 ))
    fi
done
result "$(jq -cn --argjson total "$total" --argjson online "$online" '{total: $total, online: $online}')"
