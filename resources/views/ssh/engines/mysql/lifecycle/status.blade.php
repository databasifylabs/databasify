@include('ssh.engines.mysql.helpers')
own_state | tr '\t' ' ' || echo "UNKNOWN OFFLINE"
msql "SELECT VERSION()" | sed 's/^/version:/' || true
