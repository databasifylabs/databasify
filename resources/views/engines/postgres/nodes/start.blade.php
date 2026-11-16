@include('engines.postgres._psql')
@foreach ($units as $unit)
unit_do enable {!! $sh->arg($unit) !!}
unit_do restart {!! $sh->arg($unit) !!}
@endforeach
echo "Started PostgreSQL."
