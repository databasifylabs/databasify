@include('engines.mongodb._mongo')
MONGO_TIMEOUT=840 mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  const config = configOf(primary.admin);
  if (memberOf(config, env.GONE)) reconfig(primary.admin, withoutHost(config, env.GONE), false);
  emit('Removed ' + env.GONE + ' from the replica set.');
});
JS
