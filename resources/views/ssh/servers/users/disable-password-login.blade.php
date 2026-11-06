printf 'PasswordAuthentication no\nKbdInteractiveAuthentication no\n' > /etc/ssh/sshd_config.d/10-databasify.conf
install -d -m 755 /run/sshd
sshd -t
for unit in ssh sshd; do
    systemctl is-active --quiet "$unit" && systemctl reload "$unit"
done
echo "password login disabled"
