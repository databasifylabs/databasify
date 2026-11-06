id -u {{ $username }} > /dev/null 2>&1 || useradd --create-home --shell /bin/bash {{ $username }}
home=$(getent passwd {{ $username }} | cut -d: -f6)
chmod 750 "$home"
@if ($publicKey)
install -d -m 700 -o {{ $username }} "$home/.ssh"
printf '%s\n' '{{ $publicKey }}' > "$home/.ssh/authorized_keys"
chown {{ $username }} "$home/.ssh/authorized_keys"
chmod 600 "$home/.ssh/authorized_keys"
@include('ssh.servers.os.selinux')
selinux_restore "$home/.ssh"
@endif
echo "user {{ $username }} ready"
