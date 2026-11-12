@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const config = configOf(local());
  const status = run('admin', { replSetGetStatus: 1 });
  if (healthyVoters(config, status) * 2 > votersOf(config)) throw failure('busy', 'a majority of voting members is healthy; the replica set elects a primary on its own');
  const healthy = (status.members || []).filter((member) => numberOf(member.health) === 1).map((member) => memberOf(config, member.name)).filter(Boolean);
  reconfig(local(), withMembers(config, healthy), true);
  result({});
});
JS
