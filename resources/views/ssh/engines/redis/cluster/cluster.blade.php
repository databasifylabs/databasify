@include('ssh.engines.redis.helpers')
@include('ssh.databases.helpers')
vcli CONFIG SET replica-announce-ip {{ $privateIp }} min-replicas-to-write {{ $requiredReplicas }} min-replicas-max-lag 10 > /dev/null
databasify ctl enable --now {{ $sentinelUnit }} > /dev/null
sentinel_up() { scli PING | grep -q PONG; }
wait_for 60 sentinel_up
echo "sentinel watches {{ $primary }} on this node"
