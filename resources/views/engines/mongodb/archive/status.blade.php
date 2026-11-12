@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
if [ ! -e "$ROOT/state/restoring" ] && writable; then
    use_pbm_storage "$PBM_CONFIG" || databasify_fail internal "$PBM_ERROR"
fi
raw=$(pbm_run status --out=json) || databasify_fail internal "$PBM_ERROR"
backup_result=$(databasify ctl show -p Result --value mongodb-backup 2> /dev/null || true)
summary=$(json_eval "$raw" '
const at = (seconds) => new Date(seconds * 1000).toISOString().replace(".000Z", "Z");
const done = ((input.backups && input.backups.snapshot) || []).filter((snapshot) => snapshot.status === "done").map((snapshot) => Math.max(Number(snapshot.restoreTo || 0), Number(snapshot.completeTS || 0)));
if (done.length === 0) { print(JSON.stringify({ backups: 0, earliest: null, latest: null, lastBackup: null, problem: null })); } else {
  const chunks = ((input.backups && input.backups.pitrChunks && input.backups.pitrChunks.pitrChunks) || []).map((chunk) => Number((chunk.range && chunk.range.end) || 0));
  const newest = Math.max(...done);
  print(JSON.stringify({ backups: done.length, earliest: at(Math.min(...done)), lastBackup: at(newest), latest: at(Math.max(newest, ...chunks)), problem: (input.pitr && input.pitr.error) || null }));
}') || databasify_fail internal "pbm status is not JSON"
result "${summary%\}},\"backupResult\":\"$backup_result\"}"
