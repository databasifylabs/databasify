@include('engines.postgres._psql')
@include('engines.postgres._files')
if ! clustered; then
    result '{}'
    exit 0
fi
sed "s/^  hosts: '.*'\$/  hosts: '$HOSTS'/" /srv/postgres/conf/patroni.yml | write_file /srv/postgres/conf/patroni.yml 600 || true
unit_do reload databasify-patroni
result '{}'
