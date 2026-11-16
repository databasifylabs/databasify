main(() => {
  run('admin', { updateUser: @json($name), pwd: @json($password), mechanisms: [@json($encryption)] });
  emit('Changed the password of the user.');
});
