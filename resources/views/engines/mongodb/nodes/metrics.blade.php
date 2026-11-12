@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  const status = run('admin', { serverStatus: 1 });
  const listed = run('admin', { listDatabases: 1, nameOnly: false });
  const queries = Object.values(status.opcounters || {}).reduce((total, count) => total + Number(count), 0);
  result({ connections: Number(status.connections.current), queries: queries, dataMb: Math.floor(Number(listed.totalSize) / 1048576) });
});
JS
