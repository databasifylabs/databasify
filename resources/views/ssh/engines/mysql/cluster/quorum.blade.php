@include('ssh.engines.mysql.helpers')
msql "SELECT COUNT(*), COALESCE(SUM(MEMBER_STATE = 'ONLINE'), 0) FROM performance_schema.replication_group_members" | awk '{ print "total=" $1; print "online=" $2 }'
