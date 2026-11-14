[mysqld]
datadir={!! $home !!}/data
socket={!! $socket !!}
pid-file={!! $home !!}/run/mariadbd.pid
port={!! $port !!}
bind-address=*
server_id={!! $serverId !!}
gtid_domain_id={!! $gtidDomain !!}
log_bin=binlog
log_slave_updates=ON
binlog_format=ROW
binlog_expire_logs_seconds=604800
ssl_ca={!! $tls !!}ca.crt
ssl_cert={!! $tls !!}server.crt
ssl_key={!! $tls !!}server.key
tls_version=TLSv1.2,TLSv1.3
plugin_load_add=auth_ed25519
performance_schema=ON
performance_schema_digests_size=10000
@if ($parsec)
plugin_load_add=auth_parsec
@endif
@if ($galera)
wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so
wsrep_cluster_name={!! $clusterName !!}
wsrep_cluster_address={!! $clusterAddress !!}
wsrep_node_address={!! $config->ip !!}
wsrep_node_name={!! $config->ip !!}
wsrep_node_incoming_address={!! $config->ip !!}:{!! $port !!}
wsrep_sst_method=mariabackup
wsrep_sst_auth=databasify_sst:{!! $config->secrets->replication !!}
wsrep_gtid_mode=ON
wsrep_gtid_domain_id=1
wsrep_provider_options="socket.ssl_key={!! $tls !!}server.key;socket.ssl_cert={!! $tls !!}server.crt;socket.ssl_ca={!! $tls !!}ca.crt;gcache.size=1G"
innodb_autoinc_lock_mode=2
innodb_force_primary_key=ON
@endif
@if ($config->readOnly || $galera)
read_only=ON
@endif
@foreach ($settings as $key => $value)
{!! $key !!}={!! $value !!}
@endforeach
@if ($galera)

[sst]
ssl-mode=VERIFY_CA
@endif
