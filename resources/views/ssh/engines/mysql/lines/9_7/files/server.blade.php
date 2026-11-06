[mysqld]
basedir = /usr
datadir = /srv/mysql/data
socket = /srv/mysql/run/mysqld.sock
pid_file = /srv/mysql/run/mysqld.pid
port = {!! $port !!}
bind_address = "*"
mysqlx = OFF
report_host = {!! $ip !!}
server_id = {!! $serverId !!}
host_cache_size = 0
skip_name_resolve = ON
gtid_mode = ON
enforce_gtid_consistency = ON
disabled_storage_engines = MyISAM,BLACKHOLE,FEDERATED,ARCHIVE,MEMORY
plugin_load_add = mysql_clone.so
innodb_flush_method = O_DIRECT
log_error_verbosity = 2
ssl_cert = /srv/mysql/tls/server.crt
ssl_key = /srv/mysql/tls/server.key
ssl_ca = /srv/mysql/tls/ca.crt
tls_version = TLSv1.2,TLSv1.3
require_secure_transport = OFF
@if ($clustered)
loose-group_replication_message_cache_size = {!! $messageCacheSize !!}
loose-group_replication_unreachable_majority_timeout = 30
@endif
@include('ssh.engines.shared.options', ['separator' => ' = '])
