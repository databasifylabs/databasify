@include('engines.postgres._psql')
echo 'Starting Patroni…'
@foreach ($units as $unit)
databasify ctl start {!! $sh->arg($unit) !!} > /dev/null 2>&1 || true
@endforeach
