work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cd "$work"
@foreach ($authority as $name => $content)
(umask 077 && echo '{{ base64_encode($content) }}' | base64 -d > "$work/{{ $name }}")
@endforeach
new_key() {
@if ($rsa)
    [ "$1" = ca.key ] && openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$1" 2>/dev/null && return
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$1" 2>/dev/null
@else
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$1" 2>/dev/null
@endif
}
if [ ! -s ca.key ]; then
    new_key ca.key
    openssl req -x509 -new -key ca.key -sha256 -days 3650 -subj '/CN={{ $authorityName }}' -out ca.crt
fi
new_key server.key
printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth{{ $combined ? ',clientAuth' : '' }}\nkeyUsage=digitalSignature{{ $rsa ? ',keyEncipherment' : '' }}\n' '{{ $subjectAltNames }}' > server.ext
openssl req -new -key server.key -subj '/CN={{ $serverName }}' \
    | openssl x509 -req -CA ca.crt -CAkey ca.key -CAcreateserial -days 825 -sha256 -extfile server.ext -out server.crt 2>/dev/null
@if ($combined)
cat server.crt server.key > server.pem
rm -f server.crt server.key
@endif
for file in "$work"/*; do
    case "$file" in *.srl|*.ext) continue ;; esac
    printf 'CERT %s %s\n' "$(basename "$file")" "$(base64 -w0 < "$file")"
done
