instance="$(msql "SELECT CONCAT(@@@report_host, ':', @@@port)")"
peer=$(reachable_peer) || { echo "$instance carries another node's data and no peer is reachable to clone from"; exit 1; }
echo "$instance carries another node's data, cloning it again from $peer"
@include('ssh.engines.mysql.lines.9_7.cluster.reclone')
echo "$instance rejoined with its own identity"
