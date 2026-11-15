@include('engines.mongodb._mongo')
MONGO_TIMEOUT=840 mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  if (primary.host === env.SELF) {
    const config = configOf(local());
    const self = memberOf(config, env.SELF) || { _id: 0, host: env.SELF };
    reconfig(local(), withMembers(config, [votingAlone(self)]), false);
    return emit('Left the replica set.');
  }
  const current = configOf(primary.admin);
  if (memberOf(current, env.SELF)) reconfig(primary.admin, withoutHost(current, env.SELF), false);
  emit('Left the replica set.');
});
JS
