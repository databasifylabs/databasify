main(() => {
  try {
    run('admin', { dropUser: @json($name) });
  } catch (error) {
    if (!hasCode(error, 11)) throw error;
  }
  emit('Dropped the user.');
});
