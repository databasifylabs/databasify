@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(HOSTS);
  for (const host of @json($nonVoting)) {
    const [next, changed] = withoutVote(configOf(primary.admin), host);
    if (changed) reconfig(primary.admin, next, false);
  }
  const wanted = @json($wanted);
  const config = configOf(primary.admin);
  const kept = membersOf(config).filter((member) => wanted.includes(member.host));
  if (kept.length !== membersOf(config).length) reconfig(primary.admin, withMembers(config, kept), false);
  emit('Updated the replica set members.');
});
