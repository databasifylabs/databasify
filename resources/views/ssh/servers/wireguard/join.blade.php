@include('ssh.servers.os.packages')
command -v wg > /dev/null 2>&1 || install_packages wireguard-tools
conflict=$(ip -4 route show match '{{ $address }}' | grep -v '^default' | grep -v 'dev databasify' | head -n 1 || true)
if [ -n "$conflict" ]; then
    echo "This server already routes {{ $range }} ($conflict), pick another network."
    exit 1
fi
install -d -m 700 /etc/wireguard
if [ ! -s /etc/wireguard/databasify.key ]; then
    (umask 077 && wg genkey > /etc/wireguard/databasify.key)
fi
@include('ssh.servers.wireguard.config')
@include('ssh.servers.os.selinux')
selinux_restore /etc/wireguard
systemctl daemon-reload
systemctl enable wg-quick@databasify > /dev/null 2>&1
systemctl restart wg-quick@databasify
@include('ssh.servers.firewall.ufw')
ufw_open databasify-network 'in on databasify'
@include('ssh.servers.firewall.firewalld')
firewalld_trust databasify
echo "joined the Databasify network as {{ $address }}"
echo "wireguard_public_key=$(wg pubkey < /etc/wireguard/databasify.key)"
