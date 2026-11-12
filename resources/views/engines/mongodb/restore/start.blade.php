@include('engines.mongodb._mongo')
@include('engines._write')
@include('engines.mongodb._pbm')
: | write_file "$ROOT/state/restoring" 600 false || true
trap 'rm -f "$ROOT/state/restoring"' EXIT

resynced() {
    local logs verdict
    pbm_run logs --event=resync --tail=20 --out=json || return 1
    logs=$PBM_OUTPUT
    verdict=$(json_eval "$logs" "
const recent = (input || []).filter((entry) => Number(entry.ts) >= Number(process.env.SINCE));
const broken = recent.find((entry) => Number(entry.s) <= 1);
print(broken ? 'error ' + broken.msg : (recent.some((entry) => entry.msg === 'succeed') ? 'succeeded' : 'waiting'));") || return 1
    if [[ $verdict == error\ * ]]; then
        fail_task "PBM could not read the backup storage: ${verdict#error }"
    fi
    [ "$verdict" = succeeded ] || return 1
    idle
}

idle() {
    local status
    pbm_run status --out=json || return 1
    status=$PBM_OUTPUT
    [ "$(json_eval "$status" 'print((input.running && input.running.type) || "")')" = '' ]
}

sync_storage() {
    use_pbm_storage "$1" || fail_task "$PBM_ERROR"
    SINCE=$(date +%s)
    export SINCE
    pbm_locked config --force-resync || fail_task "$PBM_ERROR"
    wait_until 600 2 resynced || fail_task 'the server was not ready within 10m0s'
}

phase 'stopping oplog slicing'
sync_storage "$RESTORE_CONFIG"
phase 'restoring'
pbm_locked restore "--time=$TARGET" --yes --wait || fail_task "$PBM_ERROR"
if [ -n "$ADMIN_PASSWORD" ]; then
    mongo_try member <<'JS'
main(() => { run('admin', { createUser: 'root', pwd: env.ADMIN_PASSWORD, roles: [{ role: 'root', db: 'admin' }] }); result({}); });
JS
    if [ -n "$MONGO_ERROR" ]; then
        mongo_js member > /dev/null <<'JS'
main(() => { run('admin', { updateUser: 'root', pwd: env.ADMIN_PASSWORD }); result({}); });
JS
    fi
    mongo_js member > /dev/null <<'JS'
main(() => {
  try {
    run('admin', { createRole: 'pbmAnyAction', privileges: [{ resource: { anyResource: true }, actions: ['anyAction'] }], roles: [] });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
  }
  const roles = [{ role: 'backup', db: 'admin' }, { role: 'restore', db: 'admin' }, { role: 'clusterMonitor', db: 'admin' }, { role: 'readWrite', db: 'admin' }, { role: 'pbmAnyAction', db: 'admin' }];
  try {
    run('admin', { createUser: 'databasify_pbm', pwd: env.AGENT_PASSWORD, roles: roles });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
    run('admin', { updateUser: 'databasify_pbm', pwd: env.AGENT_PASSWORD, roles: roles });
  }
  result({});
});
JS
fi
phase 'backing up'
sync_storage "$SAVED_CONFIG"
pbm_locked backup --compression=zstd --wait || fail_task "$PBM_ERROR"
pbm_locked config --set pitr.enabled=true || fail_task "$PBM_ERROR"
