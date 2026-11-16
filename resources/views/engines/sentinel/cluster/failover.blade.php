@include('engines.sentinel._cli')
@include('engines.sentinel._node')
target={!! $sh->arg($target) !!}
previous=$(sentinel_primary || true)
if [ "$previous" = "$target" ]; then
    echo "$target is already the primary."
    exit 0
fi
priority=$(cli "$target" 6379 CONFIG GET replica-priority)
if [ "$(wc -l <<< "$priority")" -lt 2 ] || [ "$(sed -n 2p <<< "$priority")" = 0 ]; then
    databasify_fail invalid_request "$target is a read-only replica and cannot become the primary"
fi
restore_priority() {
    cli_try cli "$target" 6379 CONFIG SET replica-priority 100
    cli_try cli "$target" 6379 CONFIG REWRITE
}
cli "$target" 6379 CONFIG SET replica-priority 1 > /dev/null
cli "$target" 6379 CONFIG REWRITE > /dev/null
trap restore_priority EXIT
sentinel_cli SENTINEL FAILOVER main > /dev/null
moved() {
    local current
    current=$(sentinel_primary || true)
    [ -n "$current" ] && [ "$current" != "$previous" ]
}
wait_until 120 2 moved || databasify_fail internal "$FLAVOR did not become ready within 2m0s"
if [ "$previous" = {!! $sh->arg($self) !!} ]; then
    demoted() { [ "$(current_role)" = slave ]; }
    wait_until 120 2 demoted || databasify_fail internal "$FLAVOR did not become ready within 2m0s"
fi
settled() { { [ "$(current_role)" = master ] || replica_synced; } && aof_written; }
wait_until 300 2 settled || true
if info=$(replication_info); then
    set_replica_of "$(info_field "$info" master_host)"
fi
echo "Promoted $target to primary."
