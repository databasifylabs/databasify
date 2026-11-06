scope: 'main'
name: '{!! $ip !!}'
restapi:
  listen: '0.0.0.0:{!! $patroniPort !!}'
  connect_address: '{!! $ip !!}:{!! $patroniPort !!}'
  authentication:
    username: patroni
    password: {!! $patroniPassword !!}
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
        {!! $key !!}: {!! $value !!}
@endforeach
  initdb:
    - encoding: UTF8
    - data-checksums
postgresql:
  listen: '*:{!! $port !!}'
  connect_address: '{!! $ip !!}:{!! $port !!}'
  data_dir: '/srv/postgres/data'
  bin_dir: '/usr/lib/postgresql/{!! $versionLine !!}/bin'
  pgpass: '/srv/postgres/conf/pgpass'
  use_unix_socket: true
  use_unix_socket_repl: false
  remove_data_directory_on_rewind_failure: true
  remove_data_directory_on_diverged_timelines: true
  authentication:
    superuser:
      username: postgres
      password: {!! $postgresPassword !!}
    replication:
      username: replicator
      password: {!! $replicationPassword !!}
    rewind:
      username: postgres
      password: {!! $postgresPassword !!}
  parameters:
@foreach ($local as $key => $value)
    {!! $key !!}: {!! $value !!}
@endforeach
  pg_hba:
@foreach ($rules as $rule)
    - '{!! $rule !!}'
@endforeach
tags:
  nofailover: {!! $readOnly ? 'true' : 'false' !!}
  noloadbalance: {!! $readOnly ? 'true' : 'false' !!}
  nosync: {!! $readOnly ? 'true' : 'false' !!}
  clonefrom: {!! $readOnly ? 'true' : 'false' !!}
