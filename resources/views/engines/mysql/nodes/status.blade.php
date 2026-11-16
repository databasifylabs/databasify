@verbatim
SELECT JSON_OBJECT('role', CASE
    WHEN (SELECT MEMBER_ROLE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid AND MEMBER_STATE = 'ONLINE') = 'PRIMARY' THEN 'primary'
    WHEN EXISTS (SELECT 1 FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid AND MEMBER_STATE = 'ONLINE') THEN 'replica'
    WHEN @@super_read_only = 0 THEN 'primary'
    WHEN EXISTS (SELECT 1 FROM performance_schema.replication_connection_status c JOIN performance_schema.replication_applier_status a USING (CHANNEL_NAME) WHERE c.SERVICE_STATE = 'ON' AND a.SERVICE_STATE = 'ON' AND CHANNEL_NAME NOT LIKE 'group_replication%') THEN 'replica'
    ELSE 'unknown'
END, 'version', SUBSTRING_INDEX(@@version, '-', 1));
@endverbatim
