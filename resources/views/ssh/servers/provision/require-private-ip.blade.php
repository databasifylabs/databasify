@if ($privateIp)
if ! ip -4 -o addr show | awk '{ split($4, cidr, "/"); print cidr[1] }' | grep -qxF '{{ $privateIp }}'; then
    echo "{{ $privateIp }} is not assigned on this server. Check the private IP, or set up its private network first."
    exit 1
fi
@endif
