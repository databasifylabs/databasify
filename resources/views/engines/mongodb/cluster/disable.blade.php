@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const config = configOf(local());
  const self = memberOf(config, env.SELF) || { _id: 0, host: env.SELF };
  const primary = primaryOf(hosts());
  const alone = withMembers(config, [votingAlone(self)]);
  if (primary.host === env.SELF) {
    reconfig(local(), alone, false);
    return emit('Left the replica set.');
  }
  const current = configOf(primary.admin);
  if (memberOf(current, env.SELF)) reconfig(primary.admin, withoutHost(current, env.SELF), false);
  reconfig(local(), alone, true);
  emit('Left the replica set.');
});
JS
