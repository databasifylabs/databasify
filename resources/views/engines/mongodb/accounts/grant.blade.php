main(() => {
  run('admin', { updateUser: @json($name), roles: @json($roles) });
  emit('Updated the grants of the user.');
});
