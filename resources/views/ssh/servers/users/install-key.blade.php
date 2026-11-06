home=$(getent passwd {{ $user }} | cut -d: -f6)
install -d -m 700 -o {{ $user }} "$home/.ssh"
touch "$home/.ssh/authorized_keys"
grep -qxF '{{ $publicKey }}' "$home/.ssh/authorized_keys" || printf '%s\n' '{{ $publicKey }}' >> "$home/.ssh/authorized_keys"
chown {{ $user }} "$home/.ssh/authorized_keys"
chmod 600 "$home/.ssh/authorized_keys"
@include('ssh.servers.os.selinux')
selinux_restore "$home/.ssh"
echo "app key installed for {{ $user }}"
