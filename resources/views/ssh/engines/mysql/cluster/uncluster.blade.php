@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
if in_cluster; then
    cluster_admin "dba.get_cluster().dissolve({'force': True})" > /dev/null || true
fi
for variable in $(msql "SELECT VARIABLE_NAME FROM performance_schema.persisted_variables WHERE VARIABLE_NAME LIKE 'group\_replication\_%' OR VARIABLE_NAME IN ('super_read_only', 'read_only', 'auto_increment_increment', 'auto_increment_offset')"); do
    msql "RESET PERSIST IF EXISTS $variable" > /dev/null
done
msql "SET GLOBAL super_read_only = OFF"
msql "SET GLOBAL read_only = OFF"
if [ "$(msql "SELECT COUNT(*) FROM information_schema.plugins WHERE PLUGIN_NAME = 'group_replication'")" = "1" ]; then
    msql "UNINSTALL PLUGIN group_replication" > /dev/null
fi
record_running_config
echo "mysql runs on its own on this node"
