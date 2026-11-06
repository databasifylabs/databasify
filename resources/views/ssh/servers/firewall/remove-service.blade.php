if [ -z "$(find /etc/databasify/nftables -maxdepth 1 -name '*.nft' -print -quit 2> /dev/null)" ] && [ -e /etc/systemd/system/databasify-firewall.service ]; then
    systemctl disable --now databasify-firewall.service > /dev/null 2>&1 || true
    rm -f /etc/systemd/system/databasify-firewall.service
    systemctl daemon-reload
    systemctl reset-failed databasify-firewall.service > /dev/null 2>&1 || true
    rmdir /etc/databasify/nftables /etc/databasify 2> /dev/null || true
    echo "no firewall rules left on this server, databasify-firewall.service removed"
fi
