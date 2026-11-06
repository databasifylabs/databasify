tuning=/etc/sysctl.d/99-mongodb.conf
@include('ssh.databases.host-tuning.originals')
map_count=$(sed -n 's/^vm.max_map_count = //p' "$originals" 2> /dev/null || true)
map_count=${map_count:-$(sysctl -n vm.max_map_count)}
wanted=$(cat <<CONF
vm.swappiness = 1
vm.max_map_count = $(( map_count > 262144 ? map_count : 262144 ))
net.core.somaxconn = 4096
net.ipv4.tcp_keepalive_time = 120
net.ipv4.tcp_keepalive_intvl = 10
net.ipv4.tcp_keepalive_probes = 6
CONF
)
@include('ssh.databases.host-tuning.apply')

echo "swappiness=$(cat /proc/sys/vm/swappiness) max_map_count=$(cat /proc/sys/vm/max_map_count)"
