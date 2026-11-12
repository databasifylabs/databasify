initiate_seed() {
    MONGO_TIMEOUT=300 mongo_js none > /dev/null <<'JS'
main(() => {
  if (!run('admin', { hello: 1 }).setName) {
    run('admin', { replSetInitiate: { _id: 'databasify', members: [{ _id: 0, host: env.SELF }] } });
  }
  const deadline = Date.now() + 120000;
  while (Date.now() < deadline) {
    try {
      if (run('admin', { hello: 1 }).isWritablePrimary) return result({});
    } catch (error) {}
    sleep(2000);
  }
  throw new Error('the server was not ready within 2m0s');
});
JS
}

resync() {
    MONGO_TIMEOUT=60 mongo_try peers <<'JS'
@include('engines.mongodb._replset')
main(() => { primaryOf(hosts()); result({}); });
JS
    if [ -n "$MONGO_ERROR" ]; then
        fail_task 'this member is too stale to catch up and no primary is reachable to copy from; its data stays untouched'
    fi
    unit_do stop mongod
    find "$ROOT/data" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    unit_do start mongod
    MONGO_TIMEOUT=21660 mongo_js peers > /dev/null <<'JS'
@include('engines.mongodb._replset')
main(() => {
  until(21600, '6h0m0s', () => withLocal(false, (admin) => { const state = runOn(admin, { hello: 1 }); return state.secondary || state.isWritablePrimary; }));
  result({});
});
JS
}

join_set() {
    MONGO_TIMEOUT=21660 mongo_js peers > /dev/null <<'JS'
@include('engines.mongodb._replset')
main(() => {
  retry(21600, '6h0m0s', () => {
    const primary = primaryOf(hosts());
    const [next, changed] = withMember(configOf(primary.admin), env.SELF);
    if (changed) reconfig(primary.admin, next, false);
  });
  result({});
});
JS
    phase 'syncing'
    synced=$(MONGO_TIMEOUT=21660 mongo_js peers <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const outcome = until(21600, '6h0m0s', () => {
    let state = null;
    try {
      state = withLocal(false, (admin) => runOn(admin, { hello: 1 }));
    } catch (error) {
      return false;
    }
    if (state.secondary || state.isWritablePrimary) return 'synced';
    try {
      if (withLocal(true, (admin) => tooStale(runOn(admin, { replSetGetStatus: 1 })))) return 'stale';
    } catch (error) {}
    return false;
  });
  result({ outcome: outcome });
});
JS
)
    if [[ $synced == *'"outcome":"stale"'* ]]; then
        resync
    fi
    if [ "$READ_ONLY" = true ]; then
        return 0
    fi
    phase 'voting'
    MONGO_TIMEOUT=660 mongo_js peers > /dev/null <<'JS'
@include('engines.mongodb._replset')
main(() => {
  retry(600, '10m0s', () => {
    const primary = primaryOf(hosts());
    const state = withLocal(false, (admin) => runOn(admin, { hello: 1 }));
    const [next, changed] = withVote(configOf(primary.admin), env.SELF, Boolean(state.secondary));
    if (changed) reconfig(primary.admin, next, false);
  });
  result({});
});
JS
}
