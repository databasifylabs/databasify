main(() => {
  run('admin', { grantRolesToUser: @json($name), roles: @json($roles) });
  emit('The user can be a continuous migration source.');
});
