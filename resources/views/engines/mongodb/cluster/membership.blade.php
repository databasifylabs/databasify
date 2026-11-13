@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  for (const host of env.NON_VOTING.split(',').filter(Boolean)) {
    const [next, changed] = withoutVote(configOf(primary.admin), host);
    if (changed) reconfig(primary.admin, next, false);
  }
  const wanted = env.WANTED.split(',').filter(Boolean);
  const config = configOf(primary.admin);
  const kept = membersOf(config).filter((member) => wanted.includes(member.host));
  if (kept.length !== membersOf(config).length) reconfig(primary.admin, withMembers(config, kept), false);
  emit('Updated the replica set members.');
});
JS
