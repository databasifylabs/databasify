main(() => {
  run('admin', { setParameter: 1, logLevel: @json($verbosity) });
  run('admin', { setParameter: 1, transactionLifetimeLimitSeconds: @json($lifetime) });
  run('admin', { setParameter: 1, wiredTigerEngineRuntimeConfig: @json('cache_size='.$cacheMb.'M') });
  run('admin', { profile: -1, slowms: @json($slow) });
  if (run('admin', { hello: 1 }).isWritablePrimary) {
    run('admin', { setDefaultRWConcern: 1, defaultReadConcern: { level: @json($readConcern) }, defaultWriteConcern: { w: @json($writeConcern) } });
  }
  emit('Applied the online settings.');
});
