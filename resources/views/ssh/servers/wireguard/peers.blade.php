@include('ssh.servers.wireguard.config')
wg syncconf databasify <(wg-quick strip databasify)
@include('ssh.servers.firewall.service')
rules=/etc/databasify/nftables/databasify_network.nft
{
    printf 'table inet databasify_network\n'
    printf 'delete table inet databasify_network\n'
    printf 'table inet databasify_network {\n'
@if ($memberIps === [])
    printf '    set members { type ipv4_addr; }\n'
@else
    printf '    set members { type ipv4_addr; elements = { %s } }\n' '{{ implode(', ', $memberIps) }}'
@endif
    printf '    chain input {\n'
    printf '        type filter hook input priority -10; policy accept;\n'
    printf '        udp dport %s ip saddr @members accept\n' '{{ $listenPort }}'
    printf '        udp dport %s drop\n' '{{ $listenPort }}'
    printf '    }\n'
    printf '}\n'
} > "$rules.next"
nft -c -f "$rules.next"
mv "$rules.next" "$rules"
nft -f "$rules"
@include('ssh.servers.firewall.ufw')
ufw_open databasify-network @foreach ($memberIps as $memberIp)'from {{ $memberIp }} to any port {{ $listenPort }} proto udp' @endforeach'in on databasify'
@include('ssh.servers.firewall.firewalld')
firewalld_open databasify-network '{{ $listenPort }}/udp'
echo "{{ count($peers) }} {{ count($peers) === 1 ? 'peer' : 'peers' }} on the Databasify network, port {{ $listenPort }} open to {{ count($memberIps) === 0 ? 'no one' : implode(', ', $memberIps) }}"
