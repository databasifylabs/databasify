@include('ssh.databases.helpers')
install -d -m 750 -o {{ $flavor }} -g {{ $flavor }} "$dir/conf" "$dir/data" "$dir/sentinel"
@if ($clustered)
@include('ssh.engines.redis.helpers')
peer_master() {
    for peer in {{ str_replace(',', ' ', $nodeHosts) }}; do
        [ "$peer" = '{{ $privateIp }}' ] && continue
        REDISCLI_AUTH=$(cat "$dir/conf/password") timeout 5 {{ $cli }} -h "$peer" -p {{ $sentinelPort }} SENTINEL get-master-addr-by-name {{ $master }} </dev/null 2>/dev/null | head -1 | tr -d '\r' | grep -E '^[0-9a-f.:]+$' && return
    done
    sed -n 's/^sentinel monitor {{ $master }} \([^ ]*\) .*/\1/p' "$dir/sentinel/sentinel.conf" 2>/dev/null | head -1
}
master=$(peer_master || true)
if [ -n "$master" ]; then
    sed -i '/^replicaof /d' "$conf"
    [ "$master" = '{{ $privateIp }}' ] || echo "replicaof $master {{ $dataPort }}" >> "$conf"
    echo "boots as $([ "$master" = '{{ $privateIp }}' ] && echo primary || echo "replica of $master"), as sentinel reports"
fi
@unless ($readOnly)
peer_has_keys() {
    for peer in {{ str_replace(',', ' ', $nodeHosts) }}; do
        [ "$peer" = '{{ $privateIp }}' ] && continue
        keys=$(REDISCLI_AUTH=$(cat "$dir/conf/password") timeout 5 {{ $cli }} -h "$peer" -p {{ $dataPort }} DBSIZE </dev/null 2>/dev/null | tr -dc '0-9')
        [ "${keys:-0}" -gt 0 ] && return 0
    done
    return 1
}
rm -f "$start_refused"
if ! grep -q '^replicaof ' "$conf" && restart_loses_data && peer_has_keys; then
    echo "refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first" > "$start_refused"
fi
@endunless
@endif
