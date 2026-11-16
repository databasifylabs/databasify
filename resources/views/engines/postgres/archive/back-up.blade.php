@include('engines.postgres._psql')
@include('engines.postgres._backrest')
@if ($createStanza)
backrest /srv/postgres/conf/pgbackrest.conf stanza-create > /dev/null 2>&1 || true
@endif
@if ($startBackup)
databasify ctl start --no-block databasify-postgres-backup > /dev/null 2>&1 || true
@endif
