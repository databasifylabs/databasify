@include('engines.postgres._psql')
@if ($etcdOnly)
unit_do start databasify-etcd
echo "Started etcd."
@else
@foreach ($units as $unit)
databasify ctl start {!! $sh->arg($unit) !!} > /dev/null 2>&1 || true
@endforeach
echo "Started every unit."
@endif
