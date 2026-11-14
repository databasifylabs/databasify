@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._server')
engine_directory tls
changed=false
printf '%s' "$CERTIFICATE" | write_file "$ROOT/tls/server.crt" 644 true && changed=true
printf '%s' "$KEY" | write_file "$ROOT/tls/server.key" 600 true && changed=true
printf '%s' "$CA" | write_file "$ROOT/tls/ca.crt" 644 true && changed=true
if [ "$changed" = true ] && unit_active "$SERVER_UNIT"; then
@if ($engine->id === 'mysql')
    my '' > /dev/null <<< 'ALTER INSTANCE RELOAD TLS'
@else
    my '' > /dev/null <<< 'FLUSH SSL'
@endif
fi
echo "Installed the TLS certificate."
