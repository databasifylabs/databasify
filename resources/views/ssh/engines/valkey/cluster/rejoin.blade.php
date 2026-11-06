@include('ssh.engines.valkey.cluster.recovery-helpers')
@include('ssh.databases.helpers')
databasify ctl reset-failed {{ $serverUnit }} {{ $sentinelUnit }} > /dev/null 2>&1 || true
databasify ctl start {{ $sentinelUnit }} > /dev/null 2>&1 || true
found() { primary=$(newest_primary || true); [ -n "$primary" ]; }
wait_for 120 found
sed -i '/^replicaof /d' "$conf"
if [ "$primary" != '{{ $privateIp }}' ]; then
    echo "replicaof $primary {{ $dataPort }}" >> "$conf"
fi
@unless ($readOnly)
if restart_loses_data && ! grep -q '^replica-priority 0$' "$conf"; then
    echo 'replica-priority 0' >> "$conf"
    echo "held back from promotion until its first full sync finishes"
fi
@endunless
if databasify ctl is-active --quiet {{ $serverUnit }}; then
    vcli REPLICAOF "$primary" {{ $dataPort }} > /dev/null || true
else
    databasify ctl start {{ $serverUnit }}
fi
synced() {
    info=$(vcli INFO replication)
    grep -q '^master_link_status:up' <<< "$info" && grep -q '^master_sync_in_progress:0' <<< "$info"
}
wait_for 600 synced
@unless ($readOnly)
if grep -q '^replica-priority 0$' "$conf"; then
    vcli CONFIG SET replica-priority 100 > /dev/null
    sed -i '/^replica-priority 0$/d' "$conf"
    echo "first full sync finished, promotable again"
fi
@endunless
echo "rejoined as a replica of $primary"
