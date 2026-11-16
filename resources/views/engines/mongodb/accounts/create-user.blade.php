main(() => {
  const user = { pwd: @json($password), mechanisms: [@json($encryption)], roles: @json($roles) };
  try {
    run('admin', Object.assign({ createUser: @json($name) }, user));
    emit('Created the user.');
  } catch (error) {
    if (codeOf(error) !== 'already_exists') throw error;
    run('admin', Object.assign({ updateUser: @json($name) }, user));
    emit('Updated the user.');
  }
});
