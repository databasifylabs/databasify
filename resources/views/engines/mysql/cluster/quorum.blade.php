SELECT JSON_OBJECT(
    'total', GREATEST(COUNT(*), {{ (int) $voters }}),
@verbatim
    'online', IF((SELECT MEMBER_STATE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid) = 'ONLINE', COALESCE(SUM(MEMBER_STATE = 'ONLINE'), 0), 0)
@endverbatim
) FROM performance_schema.replication_group_members;
