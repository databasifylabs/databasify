@include('engines.mongodb._replset')
const TARGET = @json($target);
const TARGET_HOST = @json($targetHost);
main(() => {
  const primary = primaryOf(HOSTS);
  if (primary.host === TARGET_HOST) return emit(TARGET + ' is already the primary.');
  const config = configOf(primary.admin);
  if (!memberOf(config, TARGET_HOST)) throw failure('not_found', TARGET + ' is not a member of the replica set');
  reconfig(primary.admin, withPriority(config, TARGET_HOST, 2), false);
  try {
    runOn(primary.admin, { replSetStepDown: 60, secondaryCatchUpPeriodSecs: 10 });
  } catch (error) {
    if (codeOf(error) !== 'unavailable') throw error;
  }
  const promoted = peer(TARGET_HOST);
  until(600, '10m0s', () => runOn(promoted, { hello: 1 }).isWritablePrimary);
  reconfig(promoted, withPriority(configOf(promoted), TARGET_HOST, 1), false);
  emit('Promoted ' + TARGET + ' to primary.');
});
