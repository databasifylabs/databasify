@include('engines.postgres._psql')
@include('engines.postgres._cluster')
online=0
for host in {!! collect($voters)->map($sh->arg(...))->implode(' ') !!}; do
    state=$(curl -fsS --max-time 5 "http://$host:8008/patroni" 2> /dev/null | jq -r '.state // ""' || true)
    if { [ "$state" = running ] || [ "$state" = streaming ]; } && etcd_ctl "http://$host:2379" endpoint health > /dev/null 2>&1; then
        online=$(( online + 1 ))
    fi
done
result "{\"online\":$online}"
