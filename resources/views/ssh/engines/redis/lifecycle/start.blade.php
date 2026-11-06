@if ($clustered && ! $readOnly)
@include('ssh.engines.redis.helpers')
if [ -s "$start_refused" ]; then
    cat "$start_refused"
    exit 1
fi
if grep -q '^replicaof ' "$conf" && restart_loses_data; then
    echo 'replica-priority 0' >> "$conf"
    echo "held back from promotion until its first full sync finishes"
fi
@endif
@include('ssh.engines.shared.start')
