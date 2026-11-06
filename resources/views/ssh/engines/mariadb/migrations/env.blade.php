state={{ $stateDir }}
export SOURCE_HOST SOURCE_PORT SOURCE_USER MYSQL_PWD SOURCE_SSL
SOURCE_HOST='{{ $host }}'
SOURCE_PORT='{{ $port }}'
SOURCE_USER=$(echo '{{ base64_encode($username) }}' | base64 -d)
MYSQL_PWD=$(echo '{{ base64_encode($password) }}' | base64 -d)
SOURCE_SSL='{{ $tls ? '--ssl --skip-ssl-verify-server-cert' : '--skip-ssl' }}'
in_db() { "$@" </dev/null; }
