@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
if [ ! -e "$ROOT/state/restoring" ] && writable; then
    use_pbm_storage "$PBM_CONFIG" || databasify_fail internal "$PBM_ERROR"
fi
pbm_run status --out=json || databasify_fail internal "$PBM_ERROR"
backup_result=$(databasify ctl show -p Result --value mongodb-backup 2> /dev/null || true)
output=$(BACKUP_RESULT=$backup_result json_eval "$PBM_OUTPUT" 'print(JSON.stringify({ pbm: input, backupResult: process.env.BACKUP_RESULT }))') || databasify_fail internal 'pbm status is not JSON'
result "$output"
