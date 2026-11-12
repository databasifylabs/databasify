@include('engines.mongodb._mongo')
MONGO_TIMEOUT=840 mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  if (primary.host === env.TARGET_HOST) return result({});
  const config = configOf(primary.admin);
  if (!memberOf(config, env.TARGET_HOST)) throw failure('not_found', env.TARGET + ' is not a member of the replica set');
  reconfig(primary.admin, withPriority(config, env.TARGET_HOST, 2), false);
  try {
    runOn(primary.admin, { replSetStepDown: 60, secondaryCatchUpPeriodSecs: 10 });
  } catch (error) {
    if (codeOf(error) !== 'unavailable') throw error;
  }
  const promoted = peer(env.TARGET_HOST);
  until(600, '10m0s', () => runOn(promoted, { hello: 1 }).isWritablePrimary);
  reconfig(promoted, withPriority(configOf(promoted), env.TARGET_HOST, 1), false);
  result({});
});
JS
