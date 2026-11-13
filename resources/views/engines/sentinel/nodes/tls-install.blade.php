@include('engines.sentinel._cli')
@include('engines._write')
engine_directory tls
changed=false
printf '%s' "$PEM" | write_file "$ROOT/tls/server.pem" 600 true && changed=true
printf '%s' "$CA" | write_file "$ROOT/tls/ca.crt" 644 true && changed=true
if [ "$changed" = true ] && unit_active "$FLAVOR-server"; then
    local_cli CONFIG SET tls-cert-file "$ROOT/tls/server.pem" tls-key-file "$ROOT/tls/server.pem" tls-ca-cert-file "$ROOT/tls/ca.crt" > /dev/null
fi
echo "Installed the TLS certificate."
