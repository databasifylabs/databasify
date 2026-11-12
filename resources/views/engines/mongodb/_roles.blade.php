function rolesOf(grants, replication) {
  const existing = run('admin', { listDatabases: 1, nameOnly: true }).databases.map((database) => database.name);
  const roles = grants.map((grant) => {
    if (!existing.includes(grant.database)) throw failure('not_found', 'database ' + JSON.stringify(grant.database) + ' does not exist');
    return { role: grant.access === 'write' ? 'readWrite' : 'read', db: grant.database };
  });
  if (replication) roles.push({ role: 'clusterMonitor', db: 'admin' });
  return roles;
}
function notFoundAsUser(error) {
  if (hasCode(error, 11)) throw failure('not_found', 'user ' + JSON.stringify(env.NAME) + ' does not exist');
  throw error;
}
