@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
MONGO_HOST=$SOURCE_HOST MONGO_TLS=$SOURCE_TLS MONGO_USER=$SOURCE_USER MONGO_SECRET=$SOURCE_PASSWORD mongo_try root <<'JS'
main(() => {
  const version = run('admin', { buildInfo: 1 }).version;
  const listed = run('admin', { listDatabases: 1 }).databases.map((database) => ({ name: database.name, size: Number(database.sizeOnDisk || 0) }));
  let setName = '';
  try {
    setName = run('admin', { hello: 1 }).setName || '';
  } catch (error) {}
  result({ version: version, setName: setName, listed: listed });
});
JS
if [ -n "$MONGO_ERROR" ]; then
    result "$(ERROR_TEXT=$MONGO_ERROR_TEXT mongosh --nodb --quiet --norc --eval 'print(JSON.stringify({ error: String(process.env.ERROR_TEXT).split("\n")[0] }))')"
    exit 0
fi
SOURCE_FACTS=${MONGO_OUTPUT#DATABASIFY_RESULT } mongo_js root <<'JS'
main(() => {
  const source = JSON.parse(env.SOURCE_FACTS);
  const reserved = JSON.parse(env.RESERVED_DATABASES);
  const listed = source.listed.filter((database) => !reserved.includes(database.name));
  const databases = listed.map((database) => database.name);
  const requested = JSON.parse(env.DATABASES);
  const wanted = requested.length === 0 ? databases : requested;
  const unreadable = wanted.filter((name) => !databases.includes(name));
  const readable = wanted.filter((name) => databases.includes(name));
  const size = readable.reduce((total, name) => total + listed.find((database) => database.name === name).size, 0);
  const occupied = readable.filter((name) => {
    try {
      return run(name, { listCollections: 1, nameOnly: true }).cursor.firstBatch.some((collection) => collection.name !== '_databasify');
    } catch (error) {
      return false;
    }
  });
  result({ error: null, version: source.version, setName: source.setName, databases: databases, unreadable: unreadable, occupied: occupied, size: size });
});
JS
