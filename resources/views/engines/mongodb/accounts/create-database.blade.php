@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  try {
    run(env.NAME, { create: '_databasify' });
  } catch (error) {
    if (!hasCode(error, 48)) throw error;
  }
  result({});
});
JS
