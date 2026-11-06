@include('ssh.engines.mysql.helpers')
@include('ssh.engines.mysql.migrations.helpers')
@include('ssh.databases.helpers')
printf '%s' "STOP REPLICA FOR CHANNEL 'migration'; RESET REPLICA ALL FOR CHANNEL 'migration';" | base64 | msql_from > /dev/null 2>&1 || true
echo '{{ base64_encode($sql) }}' | msql_from
replicating() { status=$(migration_status); grep -q '^Replica_IO_Running=Yes' <<< "$status" && grep -q '^Replica_SQL_Running=Yes' <<< "$status"; }
wait_for 120 replicating
in_mysql rm -rf "$state"
echo "replicating from the source on channel migration"
