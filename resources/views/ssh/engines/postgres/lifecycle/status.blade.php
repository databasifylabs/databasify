@include('ssh.engines.postgres.helpers')
node_state || echo "role:unknown state:unknown"
pg "SHOW server_version" | sed 's/ .*//; s/^/version:/' || true
