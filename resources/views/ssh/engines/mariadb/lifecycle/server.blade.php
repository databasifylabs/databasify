set -euo pipefail
@include('ssh.engines.mariadb.lifecycle.fingerprint')
options=--defaults-extra-file={{ $configFile }}
start_position=$(galera_recovery "$options")
record_running_config
if rm {{ $newClusterFlag }} 2> /dev/null; then
    exec mariadbd "$options" $start_position --wsrep-new-cluster
fi
exec mariadbd "$options" $start_position
