@include('ssh.engines.valkey.helpers')
@include('ssh.databases.helpers')
crashed() { [ "$(databasify ctl show -p NRestarts --value {{ $serverUnit }})" -gt 3 ]; }
synced() {
    info=$(vcli INFO replication)
    if ! grep -q '^replicaof ' "$conf"; then
        grep -q '^role:master' <<< "$info"
        return
    fi
    grep -q '^role:slave' <<< "$info" && grep -q '^master_link_status:up' <<< "$info" && follows_the_primary "$info"
}
follows_the_primary() {
@if ($clustered)
    primary=$(scli SENTINEL get-master-addr-by-name {{ $master }} | head -n 1)
    if [ -z "$primary" ] || [ "$primary" = '{{ $privateIp }}' ] || grep -q "^master_host:$primary$" <<< "$1"; then
        return 0
    fi
    echo "replicating from $(sed -n 's/^master_host://p' <<< "$1") while sentinel serves from $primary, moving to the primary"
    vcli REPLICAOF "$primary" {{ $dataPort }} > /dev/null
    sed -i "s/^replicaof .*/replicaof $primary {{ $dataPort }}/" "$conf"
    return 1
@else
    return 0
@endif
}
ready() {
    if crashed; then
        databasify ctl logs --unit {{ $serverUnit }} --lines 20
        echo "{{ $flavor }} keeps stopping while it starts"
        exit 1
    fi
    databasify ctl is-active --quiet {{ $serverUnit }} && synced && aof_written
}
tls_up() { ss -ltn | grep -q ':{{ $clientPort }} '; }
wait_for {{ $deadline }} ready
wait_for 300 tls_up
@unless ($readOnly)
if grep -q '^replica-priority 0$' "$conf"; then
    vcli CONFIG SET replica-priority 100 > /dev/null
    sed -i '/^replica-priority 0$/d' "$conf"
    echo "first full sync finished, promotable again"
fi
@endunless
vcli INFO replication | grep -E '^(role|master_host|master_link_status|connected_slaves)'
