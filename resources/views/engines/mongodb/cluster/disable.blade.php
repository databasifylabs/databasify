@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(HOSTS);
  if (primary.host === SELF) {
    const others = membersOf(configOf(local())).filter((member) => member.host !== SELF);
    if (others.length > 0) throw failure('busy', 'this node is still the primary of a replica set with other members; fail over to another member first, then remove it');
    return emit('Left the replica set.');
  }
  const current = configOf(primary.admin);
  if (memberOf(current, SELF)) reconfig(primary.admin, withoutHost(current, SELF), false);
  emit('Left the replica set.');
});
