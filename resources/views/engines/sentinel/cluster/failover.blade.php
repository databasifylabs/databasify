@include('engines.sentinel._cli')
@include('engines.sentinel._node')
prefer_target() {
    local current
    current=$(cli "$TARGET" 6379 CONFIG GET replica-priority)
    if [ "$(wc -l <<< "$current")" -lt 2 ] || [ "$(sed -n 2p <<< "$current")" = 0 ]; then
        databasify_fail invalid_request "$TARGET is a read-only replica and cannot become the primary"
    fi
    cli "$TARGET" 6379 CONFIG SET replica-priority "$1" > /dev/null
    cli "$TARGET" 6379 CONFIG REWRITE > /dev/null
}
previous=$(sentinel_primary || true)
if [ "$previous" = "$TARGET" ]; then
    result '{}'
    exit 0
fi
prefer_target 1
trap 'cli_try cli "$TARGET" 6379 CONFIG SET replica-priority 100; cli_try cli "$TARGET" 6379 CONFIG REWRITE' EXIT
sentinel_cli SENTINEL FAILOVER main > /dev/null
moved() {
    local current
    current=$(sentinel_primary || true)
    [ -n "$current" ] && [ "$current" != "$previous" ]
}
wait_until 120 2 moved || databasify_fail internal "$FLAVOR did not become ready within 2m0s"
if [ "$previous" = "$SELF" ]; then
    demoted() { [ "$(current_role)" = slave ]; }
    wait_until 120 2 demoted || databasify_fail internal "$FLAVOR did not become ready within 2m0s"
fi
settled() { { [ "$(current_role)" = master ] || replica_synced; } && aof_written; }
wait_until 300 2 settled || true
info=$(replication_info || true)
set_replica_of "$(info_field "$info" master_host)"
result '{}'
