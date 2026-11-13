[databases]
postgres = host=127.0.0.1 port={!! $port !!} dbname=postgres pool_size=2
%include /srv/postgres/conf/pgbouncer-pools.ini

[pgbouncer]
listen_addr = *
listen_port = {!! $poolPort !!}
unix_socket_dir =
auth_type = scram-sha-256
auth_file = /srv/postgres/conf/pgbouncer-users.txt
auth_user = pgbouncer
auth_dbname = postgres
auth_query = SELECT username, password FROM pgbouncer.get_auth($1)
client_tls_sslmode = require
client_tls_cert_file = /srv/postgres/tls/server.crt
client_tls_key_file = /srv/postgres/tls/server.key
server_tls_sslmode = require
max_client_conn = {!! $maxClients !!}
default_pool_size = {!! $defaultPoolSize !!}
ignore_startup_parameters = extra_float_digits,options
