@include('engines.postgres._psql')
@include('engines.postgres._files')
changed=false
ensure_directory /srv/postgres/tls
printf '%s' {!! $sh->arg($certificate) !!} | write_file /srv/postgres/tls/server.crt 644 && changed=true
printf '%s' {!! $sh->arg($key) !!} | write_file /srv/postgres/tls/server.key 600 && changed=true
printf '%s' {!! $sh->arg($ca) !!} | write_file /srv/postgres/tls/ca.crt 644 && changed=true
if [ "$changed" = true ] && { unit_active databasify-postgres || unit_active databasify-patroni; }; then
    pg postgres > /dev/null <<< 'SELECT pg_reload_conf()'
    unit_do reload databasify-pgbouncer
fi
echo "Installed the TLS certificate."
