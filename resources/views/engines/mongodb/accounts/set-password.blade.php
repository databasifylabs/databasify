@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._roles')
main(() => {
  try {
    run('admin', { updateUser: env.NAME, pwd: env.PASSWORD, mechanisms: [env.ENCRYPTION] });
  } catch (error) {
    notFoundAsUser(error);
  }
  result({});
});
JS
