@include('ssh.databases.host-tuning.originals')
install -d -m 755 "$sysctl_originals_dir"
if [ ! -f "$tuning" ] && [ ! -f "$originals" ]; then
    awk -F ' = ' '{ print $1 }' <<< "$wanted" | while read -r key; do echo "$key = $(sysctl -n "$key")"; done > "$originals"
fi
printf '%s\n' "$wanted" > "$tuning"
sysctl --system > /dev/null
