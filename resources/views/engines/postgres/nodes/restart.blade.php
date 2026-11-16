@include('engines.postgres._psql')
unit_do restart {!! $sh->arg($unit) !!}
echo "Restarted PostgreSQL."
