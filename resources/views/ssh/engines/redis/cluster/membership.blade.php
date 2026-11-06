@include('ssh.engines.redis.helpers')
scli SENTINEL SET main quorum {{ $quorum }} > /dev/null || true
vcli CONFIG SET min-replicas-to-write {{ $requiredReplicas }} > /dev/null || true
sed -i 's/^min-replicas-to-write .*/min-replicas-to-write {{ $requiredReplicas }}/' "$conf"
echo "membership updated: quorum {{ $quorum }}, replicas required for writes {{ $requiredReplicas }}"
