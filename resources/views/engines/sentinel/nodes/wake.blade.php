@include('engines.sentinel._cli')
databasify ctl stop "$FLAVOR-sentinel" > /dev/null 2>&1 || true
databasify ctl kill -s KILL "$FLAVOR-server" > /dev/null 2>&1 || true
unit_do stop "$FLAVOR-server"
result '{}'
