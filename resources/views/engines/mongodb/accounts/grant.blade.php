@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._roles')
main(() => {
  const roles = rolesOf(JSON.parse(env.GRANTS), env.REPLICATION === 'true');
  try {
    run('admin', { updateUser: env.NAME, roles: roles });
  } catch (error) {
    notFoundAsUser(error);
  }
  result({});
});
JS
