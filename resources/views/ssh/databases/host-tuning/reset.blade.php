@include('ssh.databases.host-tuning.originals')
if [ -f "$originals" ]; then
    sysctl -q -p "$originals" > /dev/null || true
    rm -f "$originals"
    echo "restored the kernel settings this server had before the database tuned it"
fi
rmdir "$sysctl_originals_dir" /etc/databasify 2> /dev/null || true
sysctl --system > /dev/null 2>&1 || true
