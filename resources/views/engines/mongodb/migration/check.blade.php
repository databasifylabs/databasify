@include('engines.mongodb._mongo')
MONGO_HOST=$SOURCE_HOST MONGO_TLS=$SOURCE_TLS MONGO_USER=$SOURCE_USER MONGO_SECRET=$SOURCE_PASSWORD mongo_try root <<'JS'
main(() => {
  const version = run('admin', { buildInfo: 1 }).version;
  const listed = run('admin', { listDatabases: 1 }).databases.map((database) => ({ name: database.name, size: Number(database.sizeOnDisk || 0) }));
  const roles = run('admin', { connectionStatus: 1 }).authInfo.authenticatedUserRoles.filter((role) => role.db === 'admin').map((role) => role.role);
  let setName = '';
  try {
    setName = run('admin', { hello: 1 }).setName || '';
  } catch (error) {}
  result({ version: version, setName: setName, listed: listed, roles: roles });
});
JS
if [ -n "$MONGO_ERROR" ]; then
    output=$(ERROR_TEXT=$MONGO_ERROR_TEXT mongosh --nodb --quiet --norc --eval 'print(JSON.stringify({ error: String(process.env.ERROR_TEXT).split("\n")[0] }))')
    result "$output"
    exit 0
fi
SOURCE_FACTS=${MONGO_OUTPUT#DATABASIFY_RESULT } mongo_js root <<'JS'
main(() => {
  const source = JSON.parse(env.SOURCE_FACTS);
  const occupied = source.listed.map((database) => database.name).filter((name) => {
    try {
      return run(name, { listCollections: 1, nameOnly: true }).cursor.firstBatch.some((collection) => collection.name !== '_databasify');
    } catch (error) {
      return false;
    }
  });
  result(Object.assign(source, { occupied: occupied }));
});
JS
