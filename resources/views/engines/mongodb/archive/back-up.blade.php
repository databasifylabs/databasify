@include('engines.mongodb._mongo')
databasify ctl start --no-block mongodb-backup > /dev/null 2>&1 || true
result '{}'
