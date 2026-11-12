@include('engines.mongodb._mongo')
@include('engines._write')
engine_directory tls
changed=false
printf '%s' "$PEM" | write_file "$ROOT/tls/server.pem" 600 true && changed=true
printf '%s' "$CA" | write_file "$ROOT/tls/ca.crt" 644 true && changed=true
if [ "$changed" = true ] && unit_active mongod; then
    mongo_js root > /dev/null <<'JS'
main(() => {
  run('admin', { rotateCertificates: 1 });
  result({});
});
JS
fi
result '{}'
