main(() => {
  const state = run('admin', { hello: 1 });
  const role = state.isWritablePrimary ? 'primary' : (state.secondary ? 'replica' : 'unknown');
  result({ role: role, version: run('admin', { buildInfo: 1 }).version });
});
