listen_addresses = '*'
port = '{!! $port !!}'
hba_file = '/srv/postgres/conf/pg_hba.conf'
@foreach ($clusterWide as $key => $value)
{!! $key !!} = {!! $q($value) !!}
@endforeach
@foreach ($local as $key => $value)
{!! $key !!} = {!! $q($value) !!}
@endforeach
