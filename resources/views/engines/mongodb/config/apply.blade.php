@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  run('admin', { setParameter: 1, logLevel: Number(env.VERBOSITY) });
  run('admin', { setParameter: 1, transactionLifetimeLimitSeconds: Number(env.LIFETIME) });
  run('admin', { setParameter: 1, wiredTigerEngineRuntimeConfig: 'cache_size=' + env.CACHE_MB + 'M' });
  run('admin', { profile: -1, slowms: Number(env.SLOW) });
  if (run('admin', { hello: 1 }).isWritablePrimary) {
    const writeConcern = /^-?[0-9]+$/.test(env.WRITE_CONCERN) ? Number(env.WRITE_CONCERN) : env.WRITE_CONCERN;
    run('admin', { setDefaultRWConcern: 1, defaultReadConcern: { level: env.READ_CONCERN }, defaultWriteConcern: { w: writeConcern } });
  }
  result({});
});
JS
