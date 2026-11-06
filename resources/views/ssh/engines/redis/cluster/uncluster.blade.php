@include('ssh.engines.redis.helpers')
@include('ssh.databases.helpers')
databasify ctl disable --now {{ $sentinelUnit }} > /dev/null 2>&1 || true
rm -rf "$dir/sentinel"/*
vcli REPLICAOF NO ONE > /dev/null
vcli CONFIG SET min-replicas-to-write 0 > /dev/null
echo "sentinel stopped, this node serves on its own"
