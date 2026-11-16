@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
engine_directory tls
changed=false
printf '%s' {!! $sh->arg($certificate) !!} | write_file "$ROOT/tls/server.crt" 644 true && changed=true
printf '%s' {!! $sh->arg($key) !!} | write_file "$ROOT/tls/server.key" 600 true && changed=true
printf '%s' {!! $sh->arg($ca) !!} | write_file "$ROOT/tls/ca.crt" 644 true && changed=true
if [ "$changed" = true ] && unit_active {!! $sh->arg($engine->serverUnit()) !!}; then
    my '' > /dev/null <<< {!! $sh->arg($engine->id === 'mysql' ? 'ALTER INSTANCE RELOAD TLS' : 'FLUSH SSL') !!}
fi
echo "Installed the TLS certificate."
