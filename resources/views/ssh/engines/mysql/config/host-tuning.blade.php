tuning=/etc/sysctl.d/99-mysql.conf
wanted=$(cat <<'CONF'
vm.swappiness = 1
fs.aio-max-nr = 1048576
net.core.somaxconn = 4096
net.ipv4.tcp_keepalive_time = 60
net.ipv4.tcp_keepalive_intvl = 10
net.ipv4.tcp_keepalive_probes = 6
CONF
)
@include('ssh.databases.host-tuning.apply')

echo "swappiness=$(cat /proc/sys/vm/swappiness) aio-max-nr=$(cat /proc/sys/fs/aio-max-nr)"
