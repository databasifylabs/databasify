@include('engines._runtime')
databasify ctl kill -s KILL mongod > /dev/null 2>&1 || true
unit_do stop mongod
echo 'Stopped MongoDB.'
