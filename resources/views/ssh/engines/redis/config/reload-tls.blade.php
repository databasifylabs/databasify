@include('ssh.engines.redis.helpers')
if databasify ctl is-active --quiet {{ $serverUnit }}; then
    vcli CONFIG SET tls-cert-file {{ $directory }}/tls/server.pem tls-key-file {{ $directory }}/tls/server.pem tls-ca-cert-file {{ $directory }}/tls/ca.crt > /dev/null
else
    echo "{{ $flavor }} is not running, it loads the new certificates when it starts"
fi
