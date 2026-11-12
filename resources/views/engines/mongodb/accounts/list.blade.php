@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  const databases = run('admin', { listDatabases: 1, nameOnly: true }).databases.map((database) => database.name);
  const users = run('admin', { usersInfo: 1 }).users.map((user) => user.user);
  result({ users: users, databases: databases });
});
JS
