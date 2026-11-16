@include('engines.postgres._psql')
@include('engines.postgres._files')
printf '%s' {!! $sh->arg($poolsJson) !!} | write_file /srv/postgres/conf/pools.json 600 || true
printf '%s' {!! $sh->arg($poolsIni) !!} | write_file /srv/postgres/conf/pgbouncer-pools.ini 640 || true
if unit_active databasify-pgbouncer; then
    unit_do kill -s HUP databasify-pgbouncer
fi
echo "Saved the connection pools."
