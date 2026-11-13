set -euo pipefail
@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
if [ -e "$ROOT/state/restoring" ] || ! writable; then
    exit 0
fi
use_pbm_storage "$PBM_CONFIG" || fail_task "$PBM_ERROR"
pbm_locked backup --compression=zstd --wait || fail_task "$PBM_ERROR"
pbm_run cleanup --older-than="${RETENTION_DAYS}d" --yes || fail_task "$PBM_ERROR"
