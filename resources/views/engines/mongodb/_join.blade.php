resync() {
    MONGO_TIMEOUT=60 mongo_try peers <<'JS'
@include('engines.mongodb._replset')
main(() => { reachablePrimaryOf(HOSTS); result({}); });
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
    const primary = primaryOf(HOSTS);
    const [next, changed] = withMember(configOf(primary.admin), SELF);
    if (changed) reconfig(primary.admin, next, false);
  });
  result({});
});
JS
    echo 'Syncing…'
    synced=$(MONGO_TIMEOUT=21660 mongo_js peers <<'JS'
@include('engines.mongodb._replset')
main(() => {
  let raisedAt = null;
  const outcome = until(21600, '6h0m0s', () => {
    let state = null;
    try {
      state = withLocal(false, (admin) => runOn(admin, { hello: 1 }));
    } catch (error) {
      return false;
    }
    const own = ownConfig();
    if (state.secondary || state.isWritablePrimary) {
      if (own !== null) refuseForcedSelfAhead(own);
      return 'synced';
    }
    try {
      if (withLocal(true, (admin) => tooStale(runOn(admin, { replSetGetStatus: 1 })))) return 'stale';
    } catch (error) {}
    const stranded = own !== null && !memberOf(own, SELF);
    if (stranded && raisedAt === null && recoverStrandedSelf(own)) raisedAt = Date.now();
    if (stranded && raisedAt !== null && Date.now() - raisedAt > STRANDED_RECOVERY_SECONDS * 1000) throw failure('busy', SELF + ' did not install the config of the set within ' + STRANDED_RECOVERY_SECONDS + 's after the primary raised its config version; check that the primary can reach it, then redeploy it');
    return false;
  });
  result({ outcome: outcome });
});
JS
)
    if [[ $synced == *'"outcome":"stale"'* ]]; then
        resync
    fi
@unless ($readOnly)
    echo 'Voting…'
    MONGO_TIMEOUT=660 mongo_js peers > /dev/null <<'JS'
@include('engines.mongodb._replset')
main(() => {
  retry(600, '10m0s', () => {
    const primary = primaryOf(HOSTS);
    const state = withLocal(false, (admin) => runOn(admin, { hello: 1 }));
    const [next, changed] = withVote(configOf(primary.admin), SELF, Boolean(state.secondary));
    if (changed) reconfig(primary.admin, next, false);
  });
  result({});
});
JS
@endunless
}
