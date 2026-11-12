@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._roles')
main(() => {
  const roles = rolesOf(JSON.parse(env.GRANTS), false);
  try {
    run('admin', { createUser: env.NAME, pwd: env.PASSWORD, mechanisms: [env.ENCRYPTION], roles: roles });
    result({ created: true });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
    run('admin', { updateUser: env.NAME, pwd: env.PASSWORD, mechanisms: [env.ENCRYPTION], roles: roles });
    result({ created: false });
  }
});
JS
