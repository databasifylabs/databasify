[mariadbd]
datadir = /srv/mariadb/data
port = {!! $port !!}
bind_address = "*"
server_id = {!! $serverId !!}
gtid_domain_id = {!! $gtidDomainId !!}
log_bin = mariadb-bin
log_slave_updates = ON
binlog_format = ROW
gtid_strict_mode = ON
skip_name_resolve = ON
innodb_autoinc_lock_mode = 2
ssl_cert = /srv/mariadb/tls/server.crt
ssl_key = /srv/mariadb/tls/server.key
ssl_ca = /srv/mariadb/tls/ca.crt
tls_version = TLSv1.2,TLSv1.3
@if ($readOnly)
wsrep_on = OFF
read_only = ON
relay_log = relay-bin
@elseif ($clustered)
wsrep_on = ON
wsrep_provider = /usr/lib/galera/libgalera_smm.so
wsrep_cluster_name = databasify
wsrep_cluster_address = gcomm://{!! $members !!}
wsrep_node_address = {!! $ip !!}
wsrep_node_name = {!! $ip !!}
wsrep_node_incoming_address = {!! $ip !!}:{!! $port !!}
wsrep_sst_method = mariabackup
wsrep_sst_auth = {!! $sstAuth !!}
wsrep_gtid_mode = ON
wsrep_gtid_domain_id = 1
wsrep_provider_options = "gcache.size={!! $galeraCacheSize !!}"
read_only = ON
@endif
@include('ssh.engines.shared.options', ['separator' => ' = '])
