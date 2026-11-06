instance="$(msql "SELECT CONCAT(@@@report_host, ':', @@@port)")"
peer='{{ $primary }}'
if [ "$peer" != '{{ $privateIp }}' ] && listed_at "$peer" "$instance"; then
    echo "$instance lost its data but the cluster still lists it, cloning it again from $peer"
@include('ssh.engines.mysql.cluster.reclone')
    echo "$instance rejoined the cluster"
fi
