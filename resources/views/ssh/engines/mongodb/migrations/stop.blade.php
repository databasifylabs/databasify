@include('ssh.engines.mongodb.helpers')
databasify ctl stop {{ $migrationUnit }} > /dev/null 2>&1 || true
databasify ctl reset-failed {{ $migrationUnit }} > /dev/null 2>&1 || true
rm -rf {{ $migrationStateDir }}
mongo_js "db.getSiblingDB('{{ $checkpointDatabase }}').dropDatabase({ w: 'majority' }).ok" > /dev/null || true
echo "migration stopped"
