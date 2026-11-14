scope: 'main'
name: '{!! $config->ip !!}'
restapi:
  listen: '0.0.0.0:8008'
  connect_address: '{!! $config->ip !!}:8008'
  authentication:
    username: patroni
    password: {!! $q($passwords['agent']) !!}
etcd3:
  hosts: '{!! $etcdHosts !!}'
bootstrap:
  dcs:
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
  initdb:
    - encoding: UTF8
    - data-checksums
postgresql:
  listen: '*:{!! $port !!}'
  connect_address: '{!! $config->ip !!}:{!! $port !!}'
  data_dir: '/srv/postgres/data'
  bin_dir: '/usr/lib/postgresql/{!! $line !!}/bin'
  pgpass: '/srv/postgres/conf/pgpass'
  use_unix_socket: true
  use_unix_socket_repl: false
  remove_data_directory_on_rewind_failure: true
  remove_data_directory_on_diverged_timelines: true
  authentication:
    superuser:
      username: postgres
      password: {!! $q($passwords['admin']) !!}
    replication:
      username: replicator
      password: {!! $q($passwords['replication']) !!}
    rewind:
      username: postgres
      password: {!! $q($passwords['admin']) !!}
  parameters:
@foreach ($local as $key => $value)
    {!! $key !!}: {!! $q($value) !!}
@endforeach
  pg_hba:
@foreach ($hbaRules as $rule)
    - '{!! $rule !!}'
@endforeach
tags:
  nofailover: {!! $readOnly !!}
  noloadbalance: {!! $readOnly !!}
  nosync: {!! $readOnly !!}
  clonefrom: {!! $readOnly !!}
