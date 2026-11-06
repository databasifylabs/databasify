{
    printf '[Interface]\n'
    printf 'Address = %s\n' '{{ $address }}/{{ $prefix }}'
    printf 'ListenPort = %s\n' '{{ $listenPort }}'
    printf 'PrivateKey = %s\n' "$(cat /etc/wireguard/databasify.key)"
@foreach ($peers as $peer)
    printf '\n[Peer]\n'
    printf 'PublicKey = %s\n' '{{ $peer->publicKey }}'
    printf 'AllowedIPs = %s\n' '{{ $peer->address }}/32'
    printf 'Endpoint = %s\n' '{{ $peer->endpoint }}'
    printf 'PersistentKeepalive = 25\n'
@endforeach
} > /etc/wireguard/databasify.conf.next
chmod 600 /etc/wireguard/databasify.conf.next
mv /etc/wireguard/databasify.conf.next /etc/wireguard/databasify.conf
