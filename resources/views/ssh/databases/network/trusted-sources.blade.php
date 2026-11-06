table={{ $table }}
@include('ssh.servers.firewall.ufw')
@include('ssh.servers.firewall.firewalld')
file=/etc/databasify/nftables/$table.nft
@if ($remove)
if nft list table inet "$table" > /dev/null 2>&1; then
    nft delete table inet "$table"
fi
rm -f "$file"
ufw_close "$table"
firewalld_close "$table"
echo "firewall rules of $table removed"
@include('ssh.servers.firewall.remove-service')
@else
@include('ssh.servers.firewall.service')
vpc_subnet='{{ $vpcSubnet ?? '' }}'
{
    printf 'table inet %s\n' "$table"
    printf 'delete table inet %s\n' "$table"
    printf 'table inet %s {\n' "$table"
    printf '    set peers { type ipv4_addr; flags interval; auto-merge; elements = { %s } }\n' '{{ implode(', ', $peers) }}'
@if ($sources !== [])
    printf '    set trusted { type ipv4_addr; flags interval; auto-merge; elements = { %s } }\n' '{{ implode(', ', $sources) }}'
@endif
    printf '    chain input {\n'
    printf '        type filter hook input priority -10; policy accept;\n'
    printf '        iif "lo" accept\n'
    [ -n "$vpc_subnet" ] && printf '        ip saddr %s tcp dport { %s } accept\n' "$vpc_subnet" '{{ implode(', ', $ports) }}'
@if ($peerPorts !== [])
    printf '        tcp dport { %s } ip saddr @peers accept\n' '{{ implode(', ', $peerPorts) }}'
    printf '        tcp dport { %s } drop\n' '{{ implode(', ', $peerPorts) }}'
@endif
@if ($sources !== [])
    printf '        tcp dport { %s } ip saddr @peers accept\n' '{{ implode(', ', $ports) }}'
    printf '        tcp dport { %s } ip saddr @trusted accept\n' '{{ implode(', ', $ports) }}'
    printf '        tcp dport { %s } drop\n' '{{ implode(', ', $ports) }}'
@endif
    printf '    }\n'
    printf '}\n'
} > "$file.next"
nft -c -f "$file.next"
mv "$file.next" "$file"
nft -f "$file"
ufw_open "$table" '{{ implode(',', [...$ports, ...$peerPorts]) }}/tcp'
firewalld_open "$table" '{{ implode(',', [...$ports, ...$peerPorts]) }}/tcp'
@if ($peerPorts !== [])
echo "peer ports {{ implode(', ', $peerPorts) }} accept only the database's own nodes"
@endif
@if ($sources === [])
echo "no trusted sources: ports {{ implode(', ', $ports) }} are open to everyone"
@else
echo "ports {{ implode(', ', $ports) }} accept {{ implode(', ', $sources) }}${vpc_subnet:+ and the VPC $vpc_subnet}"
@endif
@endif
