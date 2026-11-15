@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._roles')
main(() => {
  try {
    run('admin', { grantRolesToUser: env.NAME, roles: SOURCE_ROLES });
  } catch (error) {
    notFoundAsUser(error);
  }
  emit(env.NAME + ' can be a continuous migration source.');
});
JS
