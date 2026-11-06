@include('ssh.engines.mysql.helpers')
@include('ssh.engines.mysql.migrations.helpers')
stop_copy
printf '%s' "STOP REPLICA FOR CHANNEL 'migration'; RESET REPLICA ALL FOR CHANNEL 'migration';" | base64 | msql_from > /dev/null 2>&1 || true
in_mysql rm -rf "$state"
echo "migration stopped and cleaned up"
