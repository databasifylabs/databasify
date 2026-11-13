[mysqld]
datadir={!! $home !!}/data
socket={!! $home !!}/run/mysqld.sock
pid-file={!! $home !!}/run/mysqld.pid
port={!! $port !!}
bind-address=*
report_host={!! $config->ip !!}
server_id={!! $serverId !!}
gtid_mode=ON
enforce_gtid_consistency=ON
log_bin=binlog
binlog_expire_logs_seconds=604800
ssl_ca={!! $home !!}/tls/ca.crt
ssl_cert={!! $home !!}/tls/server.crt
ssl_key={!! $home !!}/tls/server.key
tls_version=TLSv1.2,TLSv1.3
plugin-load-add=mysql_clone.so
performance_schema=ON
performance-schema-consumer-statements-digest=ON
@if ($nativePasswords)
mysql_native_password=ON
@endif
@if ($groupReplication)
plugin-load-add=group_replication.so
group_replication_group_name={!! $groupName !!}
group_replication_local_address={!! $config->ip !!}:{!! $groupPort !!}
group_replication_group_seeds={!! $seeds !!}
group_replication_single_primary_mode=ON
group_replication_start_on_boot=OFF
group_replication_ssl_mode=REQUIRED
group_replication_recovery_use_ssl=ON
sql_require_primary_key=ON
@endif
@if ($config->readOnly)
super_read_only=ON
replica_parallel_workers=4
@endif
@foreach ($settings as $key => $value)
{!! $key !!}={!! $value !!}
@endforeach
!includedir {!! $home !!}/conf/conf.d
