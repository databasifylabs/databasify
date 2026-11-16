@include('engines.sentinel._cli')
@include('engines.sentinel._node')
restarts() {
    databasify ctl show -p NRestarts --value "$FLAVOR-server" 2> /dev/null || echo 0
}
echo "Waiting for $FLAVOR…"
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
@if ($clustered)
        primary=$(sentinel_primary || true)
        current=$(info_field "$info" master_host)
        if [ -n "$primary" ] && [ "$primary" != {!! $sh->arg($self) !!} ] && [ "$primary" != "$current" ]; then
            echo 'Following the primary…'
            local_cli REPLICAOF "$primary" 6379 > /dev/null || fail_task "could not follow $primary"
            set_replica_of "$primary"
            return 1
        fi
@endif
    fi
    aof_written
}
until_ok 21600 6h0m0s serving
echo 'Waiting for TLS…'
until_ok 300 5m0s listening_on 25061
@if (! $readOnly)
if holding; then
    release
fi
@endif
