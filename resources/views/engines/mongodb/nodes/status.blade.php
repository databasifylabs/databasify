@include('engines.mongodb._mongo')
mongo_try none <<'JS'
main(() => {
  const state = run('admin', { hello: 1 });
  result({ role: state.isWritablePrimary ? 'primary' : (state.secondary ? 'replica' : 'unknown') });
});
JS
if [ "$MONGO_ERROR" = unavailable ]; then
    result '{"role":"offline","version":null}'
    exit 0
elif [ -n "$MONGO_ERROR" ]; then
    databasify_fail "$MONGO_ERROR" "$MONGO_ERROR_TEXT"
fi
role=$(sed -n 's/^DATABASIFY_RESULT {"role":"\([a-z]*\)"}$/\1/p' <<< "$MONGO_OUTPUT")
MONGO_ROLE=$role mongo_js member <<'JS'
main(() => result({ role: env.MONGO_ROLE, version: run('admin', { buildInfo: 1 }).version }));
JS
