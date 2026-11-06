mongo_tls=(--tls --tlsCAFile {{ $directory }}/tls/ca.crt --tlsAllowInvalidHostnames)
mongo_args=(--quiet --norc --host 127.0.0.1 --port {{ $clientPort }} "${mongo_tls[@]}")
mongo_auth="db.getSiblingDB('admin').auth('root', require('fs').readFileSync('{{ $rootPasswordFile }}', 'utf8').trim());"
mongo_open() { HOME=/tmp timeout "${2:-60}" mongosh "${mongo_args[@]}" --eval "$1" </dev/null 2>/dev/null; }
mongo_js() { mongo_open "$mongo_auth $1" "${2:-60}"; }
mongo_script() {
    script=$(mktemp --suffix=.js)
    { printf '%s\n' "$mongo_auth"; cat; } > "$script"
    status=0
    HOME=/tmp timeout "$1" mongosh "${@:2}" "$script" </dev/null || status=$?
    rm -f "$script"
    return "$status"
}
mongo_run() { mongo_script "${1:-300}" "${mongo_args[@]}"; }
mongo_run_on_primary() {
    primary=$(mongo_open 'print(db.hello().primary ?? "")')
    [ -n "$primary" ] || return 1
    mongo_script 300 --quiet --norc --host "$primary" "${mongo_tls[@]}"
}
mongo_from() { base64 -d | mongo_run; }
answering() { [ "$(mongo_open 'db.hello().ok')" = 1 ]; }
initiated() { [ "$(mongo_open 'db.hello().setName')" = {{ $replicaSet }} ]; }
writable_primary() { [ "$(mongo_open 'db.hello().isWritablePrimary')" = true ]; }
own_role() { mongo_open 'const hello = db.hello(); print(hello.isWritablePrimary ? "primary" : hello.secondary ? "secondary" : "unknown")' || echo unknown; }
serving() { own_role | grep -Eqx 'primary|secondary'; }
can_log_in() { [ "$(mongo_js 'print("yes")')" = yes ]; }
server_active() { databasify ctl is-active --quiet mongod; }
config_fingerprint() { { cat {{ $configFile }} {{ $keyfile }} 2> /dev/null || true; mongod --version; } | sha256sum | cut -d ' ' -f 1; }
record_running_config() { config_fingerprint > {{ $runningConfig }}.new && mv {{ $runningConfig }}.new {{ $runningConfig }}; }
runs_current_config() { server_active && [ "$(cat {{ $runningConfig }} 2> /dev/null)" = "$(config_fingerprint)" ]; }
restart_unless_current() {
    if runs_current_config; then
        echo "mongod already runs this configuration, no restart needed"
        return 0
    fi
    databasify ctl enable mongod > /dev/null 2>&1
    databasify ctl restart mongod
    record_running_config
}
apply_rw_concern() { mongo_js "db.adminCommand({ setDefaultRWConcern: 1, ...JSON.parse(require('fs').readFileSync('{{ $rwConcernFile }}', 'utf8')) }).ok" > /dev/null; }
pbm_agent_beating() { [ "$(mongo_js 'print(db.getSiblingDB("admin").pbmAgents.countDocuments({ hb: { $gte: Timestamp({ t: Math.floor(Date.now() / 1000) - 30, i: 0 }) } }))' 10)" -gt 0 ] 2> /dev/null; }
pbm_agent_ready() { pbm status --out json 2> /dev/null | jq -e '[.cluster[]?.nodes[]? | select(.ok == true)] | length > 0' > /dev/null; }
