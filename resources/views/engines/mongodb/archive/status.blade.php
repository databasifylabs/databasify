@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
if [ ! -e "$ROOT/state/restoring" ] && writable; then
    use_pbm_storage "$PBM_CONFIG" || databasify_fail internal "$PBM_ERROR"
fi
pbm_run status --out=json || databasify_fail internal "$PBM_ERROR"
raw=$PBM_OUTPUT
backup_result=$(databasify ctl show -p Result --value mongodb-backup 2> /dev/null || true)
summary=$(json_eval "$raw" '
const snapshots = ((input.backups && input.backups.snapshot) || []).filter((snapshot) => snapshot.status === "done").map((snapshot) => Math.max(Number(snapshot.restoreTo || 0), Number(snapshot.completeTS || 0)));
const chunks = ((input.backups && input.backups.pitrChunks && input.backups.pitrChunks.pitrChunks) || []).map((chunk) => ({ start: Number((chunk.range && chunk.range.start) || 0), end: Number((chunk.range && chunk.range.end) || 0) }));
print(JSON.stringify({ snapshots: snapshots, chunks: chunks, problem: (input.pitr && input.pitr.error) || null }));') || databasify_fail internal "pbm status is not JSON"
result "${summary%\}},\"backupResult\":\"$backup_result\"}"
