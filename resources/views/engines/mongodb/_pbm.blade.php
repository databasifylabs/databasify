json_eval() {
    JSON_INPUT=$1 mongosh --nodb --quiet --norc --eval "const input = JSON.parse(process.env.JSON_INPUT || 'null'); $2"
}

PBM_OUTPUT=''
PBM_ERROR=''

pbm_run() {
    local status
    PBM_OUTPUT=$(pbm "$@" 2>&1) && status=0 || status=$?
    if [ "$status" -eq 0 ]; then
        PBM_ERROR=''
        return 0
    fi
    PBM_ERROR="pbm $*: $(tail -n 3 <<< "$PBM_OUTPUT" | tr '\n' ' ' | sed 's/ $//')"
    return 1
}

pbm_locked() {
    local deadline=$(( $(date +%s) + 600 ))
    while true; do
        if pbm_run "$@"; then
            return 0
        fi
        if [[ $PBM_ERROR != *'another operation in progress'* ]] || [ "$(date +%s)" -ge "$deadline" ]; then
            return 1
        fi
        sleep 2
    done
}

use_pbm_storage() {
    printf '%s' "$1" | write_file "$ROOT/conf/pbm.yaml" 600 false || true
    pbm_locked config --file "$ROOT/conf/pbm.yaml"
}

writable() {
    mongo_try none <<'JS'
main(() => { if (!run('admin', { hello: 1 }).isWritablePrimary) throw new Error('not writable'); result({}); });
JS
    [ -z "$MONGO_ERROR" ]
}
