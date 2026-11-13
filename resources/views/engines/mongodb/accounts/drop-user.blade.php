@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  try {
    run('admin', { dropUser: env.NAME });
  } catch (error) {
    if (!hasCode(error, 11)) throw error;
  }
  emit('Dropped user ' + env.NAME + '.');
});
JS
