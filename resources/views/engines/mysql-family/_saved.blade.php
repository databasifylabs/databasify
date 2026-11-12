require_saved() {
    if [ ! -f "$ROOT/state/node.json" ]; then
        databasify_fail busy 'run node configure first'
    fi
}

saved() {
    jq -r "$1" "$ROOT/state/node.json"
}

saved_is() {
    jq -e "$1" "$ROOT/state/node.json" > /dev/null
}

replication_password() {
    require_saved
    REPLICATION_SECRET=$(saved '.secrets.replication // empty')
    if [ -z "$REPLICATION_SECRET" ]; then
        databasify_fail invalid_request {!! $sh->arg($engine->label.' clusters need the replication secret') !!}
    fi
}
