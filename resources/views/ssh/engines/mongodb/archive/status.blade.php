@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
@include('ssh.databases.recovery.settings-check', ['path' => $archiveConfig])
if ! writable_primary; then
    echo "problem=This node is not the primary, the archive is read there."
    exit 0
fi
mongo_run <<'JS' > /dev/null
@include('ssh.engines.mongodb.archive.users')
JS
export PBM_MONGODB_URI="{!! $pbmUri !!}"
install -d -m 700 {{ $archiveWork }}/state
applied={{ $archiveWork }}/state/applied-config
errors=$(mktemp)
trap 'rm -f "$errors"' EXIT
if [ "$(cat "$applied" 2> /dev/null)" != "$(sha256sum < {{ $archiveConfig }})" ]; then
    if ! wait_for 45 pbm_agent_beating > /dev/null; then
        echo "problem=No PBM agent is running yet, the bucket is set up once one is."
        exit 0
    fi
    if ! pbm config --file {{ $archiveConfig }} > /dev/null 2> "$errors"; then
        echo "problem=PBM could not use the bucket: $(tail -n 1 "$errors")"
        exit 0
    fi
    sha256sum < {{ $archiveConfig }} > "$applied"
fi
report=$(pbm status --out json 2> "$errors") || {
    echo "problem=PBM could not read the archive: $(tail -n 1 "$errors")"
    exit 0
}
backups=$(jq '[.backups.snapshot[]? | select(.status == "done")] | length' <<< "$report")
earliest=$(jq -r '[.backups.snapshot[]? | select(.status == "done") | .restoreTo] | min // empty' <<< "$report")
last_backup=$(jq -r '[.backups.snapshot[]? | select(.status == "done") | .restoreTo] | max // empty' <<< "$report")
latest=$(jq -r '[.backups.pitrChunks.pitrChunks[]? | .range.end] | max // empty' <<< "$report")
problem=$(jq -r '[(.pitr.error // empty), (.cluster[]?.nodes[]? | select(.ok != true) | "the PBM agent on \(.host) is not running")] | map(select(. != "")) | join("; ")' <<< "$report")
if jq -e '(.pitr.error // "") | contains("insufficient range")' <<< "$report" > /dev/null && [ "$(databasify ctl is-active {{ $backupService }} 2> /dev/null || true)" != "activating" ]; then
    databasify ctl start --no-block {{ $backupService }}
fi
@include('ssh.databases.recovery.report')
