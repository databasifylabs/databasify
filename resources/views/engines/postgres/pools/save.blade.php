@include('engines.postgres._psql')
@include('engines.postgres._files')
printf '%s' "$POOLS" | write_file /srv/postgres/conf/pools.json 600 || true
pools_ini | write_file /srv/postgres/conf/pgbouncer-pools.ini 640 || true
if unit_active databasify-pgbouncer; then
    unit_do kill -s HUP databasify-pgbouncer
fi
echo "Saved the connection pools."
