main(() => {
  const name = @json($name);
  for (const user of run('admin', { usersInfo: 1 }).users) {
    const revoked = user.roles.filter((role) => role.db === name).map((role) => ({ role: role.role, db: role.db }));
    if (revoked.length > 0) run('admin', { revokeRolesFromUser: user.user, roles: revoked });
  }
  run(name, { dropDatabase: 1 });
  emit('Dropped the database.');
});
