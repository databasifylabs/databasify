@include('engines.mongodb._mongo')
mongo_js none > /dev/null <<'JS'
main(() => {
  if (!run('admin', { hello: 1 }).setName) {
    run('admin', { replSetInitiate: { _id: 'databasify', members: [{ _id: 0, host: env.HOST }] } });
  }
  const deadline = Date.now() + 120000;
  while (Date.now() < deadline) {
    try {
      if (run('admin', { hello: 1 }).isWritablePrimary) return result({});
    } catch (error) {}
    sleep(2000);
  }
  throw new Error('the server was not ready within 2m0s');
});
JS
mongo_try none <<'JS'
main(() => {
  run('admin', { createUser: 'root', pwd: env.ADMIN_PASSWORD, roles: [{ role: 'root', db: 'admin' }] });
  result({});
});
JS
if [ -n "$MONGO_ERROR" ]; then
    mongo_js root > /dev/null <<'JS'
main(() => {
  run('admin', { updateUser: 'root', pwd: env.ADMIN_PASSWORD });
  result({});
});
JS
fi
mongo_js root > /dev/null <<'JS'
main(() => {
  try {
    run('admin', { createRole: 'pbmAnyAction', privileges: [{ resource: { anyResource: true }, actions: ['anyAction'] }], roles: [] });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
  }
  const roles = [
    { role: 'backup', db: 'admin' },
    { role: 'restore', db: 'admin' },
    { role: 'clusterMonitor', db: 'admin' },
    { role: 'readWrite', db: 'admin' },
    { role: 'pbmAnyAction', db: 'admin' },
  ];
  try {
    run('admin', { createUser: 'databasify_pbm', pwd: env.AGENT_PASSWORD, roles: roles });
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
    run('admin', { updateUser: 'databasify_pbm', pwd: env.AGENT_PASSWORD, roles: roles });
  }
  result({});
});
JS
result '{}'
