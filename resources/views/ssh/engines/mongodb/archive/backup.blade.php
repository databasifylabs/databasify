set -euo pipefail
@include('ssh.engines.mongodb.helpers')
if ! writable_primary; then
    echo "not the primary, skipping"
    exit 0
fi
export PBM_MONGODB_URI="{!! $pbmUri !!}"
for attempt in $(seq 1 60); do
    pbm_agent_ready && break
    [ "$attempt" -lt 60 ] || { echo "no PBM agent became ready within 5 minutes"; exit 1; }
    sleep 5
done
echo "taking a logical backup"
pbm backup --wait
pbm cleanup --older-than={{ $retentionDays }}d --yes
