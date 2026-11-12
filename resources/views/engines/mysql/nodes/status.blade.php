@include('engines.mysql-family._mysql')
@include('engines.mysql-family._version')

member=$(my '' <<'SQL'
@verbatim
SELECT MEMBER_ROLE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid AND MEMBER_STATE = 'ONLINE'
@endverbatim
SQL
)
if [ "$member" = PRIMARY ]; then
    status_result primary
    exit 0
elif [ -n "$member" ]; then
    status_result replica
    exit 0
fi

read_only=$(my '' <<'SQL'
@verbatim
SELECT @@super_read_only
@endverbatim
SQL
)
if [ "$read_only" = 0 ]; then
    status_result primary
    exit 0
fi

channels=$(my '' <<'SQL'
SELECT COUNT(*) FROM performance_schema.replication_connection_status c JOIN performance_schema.replication_applier_status a USING (CHANNEL_NAME) WHERE c.SERVICE_STATE = 'ON' AND a.SERVICE_STATE = 'ON' AND CHANNEL_NAME NOT LIKE 'group_replication%'
SQL
)
if [ "$channels" != 0 ]; then
    status_result replica
else
    status_result unknown
fi
