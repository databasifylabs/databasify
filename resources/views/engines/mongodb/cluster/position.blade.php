@include('engines.mongodb._mongo')
if ! unit_active mongod; then
    result '{"position":-1}'
    exit 0
fi
mongo_try root <<'JS'
main(() => {
  const self = (run('admin', { replSetGetStatus: 1 }).members || []).find((member) => member.self) || {};
  result({ position: self.optimeDate ? Math.floor(new Date(self.optimeDate).getTime() / 1000) : -1 });
});
JS
if [ -n "$MONGO_ERROR" ]; then
    result '{"position":-1}'
else
    printf '%s\n' "$MONGO_OUTPUT"
fi
