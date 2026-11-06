@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
agent={{ $pbmAgentUnit }}-restore
cleanup() {
    databasify ctl stop "$agent" > /dev/null 2>&1 || true
    rm -f {{ $restoreConfig }}
}
trap cleanup EXIT
pbm_idle() { pbm status --out json 2> /dev/null | jq -e '(.running // {}) == {}' > /dev/null; }
resync_storage() {
    for attempt in 1 2 3 4 5; do
        if ! wait_for 300 pbm_idle > /dev/null; then
            echo "PBM stayed busy with another operation for 5 minutes, so the storage could not be resynced" >&2
            return 1
        fi
        if output=$(pbm config --force-resync --wait 2>&1); then
            return 0
        fi
        if ! grep -q 'another operation in progress' <<< "$output"; then
            echo "$output" >&2
            return 1
        fi
        echo "PBM is busy (attempt $attempt): $(tail -n 1 <<< "$output")"
    done
    return 1
}
install -d -m 700 {{ $archiveWork }}
echo '{{ base64_encode($source) }}' | base64 -d | put_file '{{ $restoreConfig }}' 600 root
databasify ctl daemon-reload
databasify ctl enable mongod > /dev/null 2>&1
databasify ctl restart mongod
wait_for 300 answering
@include('ssh.engines.mongodb.lifecycle.initiate')
mongo_run <<'JS' > /dev/null
@include('ssh.engines.mongodb.archive.users')
JS
export PBM_MONGODB_URI="{!! $pbmUri !!}"
echo "reading the backups of the source"
pbm config --file {{ $restoreConfig }} > /dev/null
databasify ctl run --quiet --unit="$agent" --uid=mongodb sh -c 'PBM_MONGODB_URI="{!! $pbmUri !!}" exec pbm-agent'
wait_for 120 pbm_agent_ready
resync_storage
source_backups() { pbm status --out json 2> /dev/null | jq -e '[.backups.snapshot[]? | select(.status == "done")] | length > 0' > /dev/null; }
wait_for 300 source_backups
snapshot=$(pbm status --out json | jq -r '[.backups.snapshot[]? | select(.status == "done" and .restoreTo == {{ $targetEpoch }}) | .name] | first // empty')
if [ -n "$snapshot" ]; then
    echo "restoring the backup $snapshot, which ends at {{ $target }} UTC"
    pbm restore "$snapshot" --wait --yes
else
    echo "restoring the newest backup taken before {{ $target }} UTC and replaying the oplog up to it"
    pbm restore --time='{{ str_replace(' ', 'T', $target) }}' --wait --yes
fi
databasify ctl stop mongod
rm -f {{ $runningConfig }}
echo "restored to {{ $target }} UTC"
