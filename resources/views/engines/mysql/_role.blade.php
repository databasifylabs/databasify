node_role() {
    local member read_only channels
    member=$(my '' <<'SQL'
@verbatim
SELECT MEMBER_ROLE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid AND MEMBER_STATE = 'ONLINE'
@endverbatim
SQL
)
    if [ "$member" = PRIMARY ]; then
        echo primary
        return 0
    elif [ -n "$member" ]; then
        echo replica
        return 0
    fi
    read_only=$(my '' <<'SQL'
@verbatim
SELECT @@super_read_only
@endverbatim
SQL
)
    if [ "$read_only" = 0 ]; then
        echo primary
        return 0
    fi
    channels=$(my '' <<'SQL'
SELECT COUNT(*) FROM performance_schema.replication_connection_status c JOIN performance_schema.replication_applier_status a USING (CHANNEL_NAME) WHERE c.SERVICE_STATE = 'ON' AND a.SERVICE_STATE = 'ON' AND CHANNEL_NAME NOT LIKE 'group_replication%'
SQL
)
    if [ "$channels" != 0 ]; then
        echo replica
    else
        echo unknown
    fi
}
