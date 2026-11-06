CHANGE REPLICATION SOURCE TO
    SOURCE_HOST = {!! $host !!},
    SOURCE_PORT = {{ $port }},
    SOURCE_USER = {!! $username !!},
    SOURCE_PASSWORD = {!! $password !!},
    SOURCE_LOG_FILE = {!! $file !!},
    SOURCE_LOG_POS = {{ $position }},
    SOURCE_AUTO_POSITION = 0,
    SOURCE_SSL = {{ $tls ? 1 : 0 }},
    GET_SOURCE_PUBLIC_KEY = 1,
    SOURCE_CONNECT_RETRY = 10
    FOR CHANNEL 'migration';
@include('ssh.engines.mysql.migrations.filter')
START REPLICA FOR CHANNEL 'migration';
