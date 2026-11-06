CHANGE MASTER 'migration' TO
    MASTER_HOST = {!! $host !!},
    MASTER_PORT = {{ $port }},
    MASTER_USER = {!! $username !!},
    MASTER_PASSWORD = {!! $password !!},
    MASTER_LOG_FILE = {!! $file !!},
    MASTER_LOG_POS = {{ $position }},
    MASTER_SSL = {{ $tls ? 1 : 0 }},
    MASTER_CONNECT_RETRY = 10;
@include('ssh.engines.mariadb.migrations.filter')
START SLAVE 'migration';
