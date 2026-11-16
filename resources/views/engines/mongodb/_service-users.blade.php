const PBM_ROLES = [
  { role: 'backup', db: 'admin' },
  { role: 'restore', db: 'admin' },
  { role: 'clusterMonitor', db: 'admin' },
  { role: 'readWrite', db: 'admin' },
  { role: 'pbmAnyAction', db: 'admin' },
];
function saveUser(name, password, roles) {
  try {
    run('admin', { createUser: name, pwd: password, roles: roles });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
    run('admin', { updateUser: name, pwd: password, roles: roles });
  }
}
function saveServiceUsers(admin, agent) {
  saveUser('root', admin, [{ role: 'root', db: 'admin' }]);
  try {
    run('admin', { createRole: 'pbmAnyAction', privileges: [{ resource: { anyResource: true }, actions: ['anyAction'] }], roles: [] });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
  }
  saveUser('databasify_pbm', agent, PBM_ROLES);
}
