@include('engines.postgres._psql')
@include('engines.postgres._files')
if ! clustered; then
    echo "A single node has no member list to update."
    exit 0
fi
sed "s/^  hosts: '.*'\$/  hosts: '$HOSTS'/" /srv/postgres/conf/patroni.yml | write_file /srv/postgres/conf/patroni.yml 600 || true
unit_do reload databasify-patroni
echo "Updated the member list to $HOSTS."
