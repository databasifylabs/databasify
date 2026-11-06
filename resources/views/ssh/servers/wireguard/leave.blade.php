systemctl disable --now wg-quick@databasify > /dev/null 2>&1 || true
rm -f /etc/wireguard/databasify.conf
systemctl daemon-reload
if command -v nft > /dev/null 2>&1 && nft list table inet databasify_network > /dev/null 2>&1; then
    nft delete table inet databasify_network
fi
rm -f /etc/databasify/nftables/databasify_network.nft
@include('ssh.servers.firewall.ufw')
ufw_close databasify-network
@include('ssh.servers.firewall.firewalld')
firewalld_close databasify-network
firewalld_distrust databasify
echo "left the Databasify network"
@include('ssh.servers.firewall.remove-service')
