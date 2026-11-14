@include('engines.postgres._psql')
@include('engines.postgres._units')
for name in $(manifest_units); do
    unit_do enable "$name"
    unit_do restart "$name"
done
echo "Started PostgreSQL."
