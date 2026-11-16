@include('engines.mongodb._replset')
const GONE = @json($gone);
main(() => {
  const primary = primaryOf(HOSTS);
  const config = configOf(primary.admin);
  if (memberOf(config, GONE)) reconfig(primary.admin, withoutHost(config, GONE), false);
  emit('Removed ' + GONE + ' from the replica set.');
});
