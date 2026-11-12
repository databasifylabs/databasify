@include('engines.sentinel._cli')
databasify ctl stop "$FLAVOR-migration" > /dev/null 2>&1 || true
databasify ctl reset-failed "$FLAVOR-migration" > /dev/null 2>&1 || true
rm -rf "$ROOT/migration"
result '{}'
