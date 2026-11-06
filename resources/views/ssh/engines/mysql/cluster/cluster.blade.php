@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
databasify ctl restart mysql
up() { msql "SELECT 1" > /dev/null; }
wait_for 900 up
record_running_config
@include('ssh.engines.mysql.cluster.create-cluster')
