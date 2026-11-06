@include('ssh.engines.valkey.helpers')
vcli INFO replication | grep -E '^(role|master_host|master_link_status|master_sync_in_progress|connected_slaves)' || echo "role:unknown"
vcli INFO server | awk -F ':' '/^{{ $versionField }}:/ { print "version:" $2 }' || true
