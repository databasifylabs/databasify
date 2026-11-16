@include('engines.mongodb._mongo')
@include('engines.mongodb._pcsm')
property() {
    databasify ctl show -p "$1" --value mongodb-migration 2> /dev/null || true
}
clustersync=$(pcsm_status) || clustersync=null
output=$(LOAD_STATE=$(property LoadState) ACTIVE_STATE=$(property ActiveState) SUB_STATE=$(property SubState) EXIT_STATUS=$(property ExecMainStatus) \
    LOG=$(tail -n 3 "$ROOT/logs/mongodb-migration.log" 2> /dev/null | tr '\n' ' ' | sed 's/ $//' || true) CLUSTERSYNC=$clustersync \
    mongosh --nodb --quiet --norc --eval '
const env = process.env;
let clustersync = null;
try {
  clustersync = JSON.parse(env.CLUSTERSYNC);
} catch (error) {}
print(JSON.stringify({ loadState: env.LOAD_STATE, activeState: env.ACTIVE_STATE, subState: env.SUB_STATE, exitStatus: env.EXIT_STATUS, log: env.LOG, clustersync: clustersync }));')
result "$output"
