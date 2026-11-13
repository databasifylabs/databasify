ttl: 30
loop_wait: 10
retry_timeout: 10
maximum_lag_on_failover: 1048576
failsafe_mode: true
postgresql:
  use_pg_rewind: true
  use_slots: true
  parameters:
@foreach ($clusterWide as $key => $value)
    {!! $key !!}: {!! $q($value) !!}
@endforeach
