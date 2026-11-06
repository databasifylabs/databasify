@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
databasify ctl start mysql
up() { msql "SELECT 1" > /dev/null; }
wait_for 900 up
online=$(msql "SELECT COUNT(*) FROM performance_schema.replication_group_members WHERE MEMBER_STATE = 'ONLINE'")
if [ "$online" = "0" ]; then
    force=False
    for peer in $(msql "SELECT SUBSTRING_INDEX(address, ':', 1) FROM mysql_innodb_cluster_metadata.instances WHERE instance_type = 'group-member' AND address <> CONCAT(@@@report_host, ':', @@@port)"); do
        cluster_admin_at "$peer" "session.run_sql('SELECT 1')" 30 > /dev/null 2>&1 || force=True
    done
    echo "rebooting the cluster$([ "$force" = True ] && echo ' without the members that do not answer'); the most up-to-date member seeds it"
    cluster_admin "dba.reboot_cluster_from_complete_outage('main', {'force': $force})" > /dev/null
else
    cluster_admin "dba.get_cluster().force_quorum_using_partition_of('{{ $instance }}')" > /dev/null
fi
primary_online() { [ "$(msql "SELECT COUNT(*) FROM performance_schema.replication_group_members WHERE MEMBER_ROLE = 'PRIMARY' AND MEMBER_STATE = 'ONLINE'")" = "1" ]; }
wait_for 120 primary_online
refresh_group_seeds
primary=$(msql "SELECT CONCAT(MEMBER_HOST, ':', MEMBER_PORT) FROM performance_schema.replication_group_members WHERE MEMBER_ROLE = 'PRIMARY'")
echo "quorum restored, primary is $primary"
