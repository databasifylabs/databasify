@include('engines.sentinel._cli')
@include('engines.sentinel._node')
phase "waiting for $FLAVOR"
baseline=$(restarts)
serving() {
    if [ $(( $(restarts) - baseline )) -gt 3 ]; then
        fail_task "$FLAVOR keeps stopping while it starts"
    fi
    unit_active "$FLAVOR-server" || return 1
    local info role primary current
    info=$(replication_info) || return 1
    role=$(info_field "$info" role)
    if [ -z "$(replica_of)" ]; then
        [ "$role" = master ] || return 1
    else
        [ "$role" = slave ] && [ "$(info_field "$info" master_link_status)" = up ] || return 1
        if [ "$CLUSTERED" = true ]; then
            primary=$(sentinel_primary || true)
            current=$(info_field "$info" master_host)
            if [ -n "$primary" ] && [ "$primary" != "$SELF" ] && [ "$primary" != "$current" ]; then
                phase 'following the primary'
                local_cli REPLICAOF "$primary" 6379 > /dev/null || fail_task "could not follow $primary"
                set_replica_of "$primary"
                return 1
            fi
        fi
    fi
    aof_written
}
until_ok 21600 6h0m0s serving
phase 'waiting for TLS'
tls_listening() {
    timeout 1 bash -c 'exec 3<>/dev/tcp/127.0.0.1/25061' 2> /dev/null
}
until_ok 300 5m0s tls_listening
if [ "$READ_ONLY" != true ] && holding; then
    release
fi
