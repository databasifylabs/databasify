@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  for (const user of run('admin', { usersInfo: 1 }).users) {
    const revoked = user.roles.filter((role) => role.db === env.NAME).map((role) => ({ role: role.role, db: role.db }));
    if (revoked.length > 0) run('admin', { revokeRolesFromUser: user.user, roles: revoked });
  }
  run(env.NAME, { dropDatabase: 1 });
  result({});
});
JS
