@include('ssh.engines.postgres.migrations.helpers')
export SOURCE_HOST SOURCE_PORT SOURCE_USER PGPASSWORD SOURCE_SSLMODE
SOURCE_HOST='{{ $host }}'
SOURCE_PORT='{{ $port }}'
SOURCE_USER=$(echo '{{ base64_encode($username) }}' | base64 -d)
PGPASSWORD=$(echo '{{ base64_encode($password) }}' | base64 -d)
SOURCE_SSLMODE='{{ $tls ? 'require' : 'prefer' }}'
