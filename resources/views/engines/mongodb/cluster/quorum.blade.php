@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  let config;
  try {
    config = configOf(local());
  } catch (error) {
    return result({ total: 0, online: 0 });
  }
  const quorum = { total: votersOf(config), online: 0 };
  try {
    const status = run('admin', { replSetGetStatus: 1 });
    if (hasPrimary(status)) quorum.online = healthyVoters(config, status);
  } catch (error) {}
  result(quorum);
});
JS
