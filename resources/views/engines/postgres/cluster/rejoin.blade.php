@include('engines.postgres._psql')
@include('engines.postgres._units')
phase 'starting Patroni'
units=$(manifest_units)
for name in $units; do
    databasify ctl start "$name" > /dev/null 2>&1 || true
done
