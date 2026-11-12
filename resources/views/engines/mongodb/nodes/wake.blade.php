@include('engines.mongodb._mongo')
databasify ctl kill -s KILL mongod > /dev/null 2>&1 || true
unit_do stop mongod
result '{}'
