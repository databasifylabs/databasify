@include('engines.mongodb._mongo')
MONGO_TIMEOUT=840 mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  if (primary.host === env.SELF) {
    const others = membersOf(configOf(local())).filter((member) => member.host !== env.SELF);
    if (others.length > 0) throw failure('busy', 'this node is still the primary of a replica set with other members; fail over to another member first, then remove it');
    return emit('Left the replica set.');
  }
  const current = configOf(primary.admin);
  if (memberOf(current, env.SELF)) reconfig(primary.admin, withoutHost(current, env.SELF), false);
  emit('Left the replica set.');
});
JS
