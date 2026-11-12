@include('engines.mongodb._mongo')
@include('engines.mongodb._units')
phase 'waiting for MongoDB'
baseline=$(restarts)
serving() {
    if [ $(( $(restarts) - baseline )) -gt 3 ]; then
        fail_task 'MongoDB keeps stopping while it starts, see its log'
    fi
    mongo_try none <<'JS'
main(() => {
  const state = run('admin', { hello: 1 });
  if (!state.setName || state.isWritablePrimary || state.secondary) result({});
  else throw new Error('not ready');
});
JS
    [ -z "$MONGO_ERROR" ]
}
wait_until 21600 2 serving || fail_task 'the server was not ready within 6h0m0s'
