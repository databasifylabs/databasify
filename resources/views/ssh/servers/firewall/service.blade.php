@include('ssh.servers.os.packages')
command -v nft > /dev/null 2>&1 || install_packages nftables
install -d -m 755 /etc/databasify/nftables
cat > /etc/systemd/system/databasify-firewall.service <<'UNIT'
[Unit]
Description=Databasify firewall
After=network-pre.target firewalld.service
Before=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for rules in /etc/databasify/nftables/*.nft; do [ -f "$rules" ] && nft -f "$rules"; done; true'

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable databasify-firewall.service > /dev/null 2>&1
