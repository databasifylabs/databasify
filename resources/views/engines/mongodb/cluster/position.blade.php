@include('engines.mongodb._mongo')
if ! unit_active mongod; then
    result '{"position":-1}'
    exit 0
fi
mongo_try root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const optime = selfOf(run('admin', { replSetGetStatus: 1 })).optimeDate;
  result({ position: optime ? Math.floor(new Date(optime).getTime() / 1000) : -1 });
});
JS
if [ -n "$MONGO_ERROR" ]; then
    result '{"position":-1}'
else
    printf '%s\n' "$MONGO_OUTPUT"
fi
