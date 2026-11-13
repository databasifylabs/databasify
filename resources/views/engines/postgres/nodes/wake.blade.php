@include('engines.postgres._psql')
@include('engines.postgres._units')
if clustered && [ "$ALL" != true ]; then
    unit_do start databasify-etcd
    echo "Started etcd."
    exit 0
fi
units=$(manifest_units)
for name in $units; do
    databasify ctl start "$name" > /dev/null 2>&1 || true
done
echo "Started every unit."
