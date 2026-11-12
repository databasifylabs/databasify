@include('engines.mongodb._mongo')
mongo_js root <<'JS'
main(() => {
  const parameters = run('admin', { getParameter: 1, logLevel: 1, transactionLifetimeLimitSeconds: 1 });
  const cache = run('admin', { serverStatus: 1 }).wiredTiger.cache['maximum bytes configured'];
  const profile = run('admin', { profile: -1 });
  const options = run('admin', { getCmdLineOpts: 1 });
  const concern = run('admin', { getDefaultRWConcern: 1 });
  const net = (options.parsed && options.parsed.net) || {};
  result({ values: {
    verbosity: String(parameters.logLevel),
    transactionLifetimeLimitSeconds: String(parameters.transactionLifetimeLimitSeconds),
    cacheSizeGB: (Number(cache) / 1073741824).toFixed(2),
    slowOpThresholdMs: String(profile.slowms),
    maxIncomingConnections: String(net.maxIncomingConnections || 0),
    defaultReadConcern: String((concern.defaultReadConcern && concern.defaultReadConcern.level) || ''),
    defaultWriteConcern: String((concern.defaultWriteConcern && concern.defaultWriteConcern.w) || ''),
  } });
});
JS
