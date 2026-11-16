@include('engines.postgres._psql')
@include('engines.postgres._files')
sed {!! $sh->arg("s/^  hosts: '.*'\$/  hosts: '{$hosts}'/") !!} /srv/postgres/conf/patroni.yml | write_file /srv/postgres/conf/patroni.yml 600 || true
unit_do reload databasify-patroni
echo {!! $sh->arg("Updated the member list to {$hosts}.") !!}
