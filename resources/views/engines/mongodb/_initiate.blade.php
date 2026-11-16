function initiate(host) {
  if (!run('admin', { hello: 1 }).setName) {
    run('admin', { replSetInitiate: { _id: 'databasify', members: [{ _id: 0, host: host }] } });
  }
  const deadline = Date.now() + 120000;
  while (Date.now() < deadline) {
    try {
      if (run('admin', { hello: 1 }).isWritablePrimary) return;
    } catch (error) {}
    sleep(2000);
  }
  throw new Error('the server was not ready within 2m0s');
}
