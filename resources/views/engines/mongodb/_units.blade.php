restarts() {
    databasify ctl show -p NRestarts --value mongod 2> /dev/null || echo 0
}

archived() {
    [ -f "$ROOT/state/node.json" ] && ! grep -q '"archive":null' "$ROOT/state/node.json"
}
