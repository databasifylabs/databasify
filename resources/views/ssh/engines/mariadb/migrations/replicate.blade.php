@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
msql "STOP SLAVE 'migration'; RESET SLAVE 'migration' ALL" > /dev/null || true
echo '{{ base64_encode($sql) }}' | msql_from
migration_replicating() { replicating_on migration; }
wait_for 120 migration_replicating
rm -rf '{{ $stateDir }}'
echo "replicating from the source on connection migration"
