@include('engines.postgres._psql')
@include('engines.postgres._files')
changed=false
ensure_directory /srv/postgres/tls
printf '%s' "$CERTIFICATE" | write_file /srv/postgres/tls/server.crt 644 && changed=true
printf '%s' "$KEY" | write_file /srv/postgres/tls/server.key 600 && changed=true
printf '%s' "$CA" | write_file /srv/postgres/tls/ca.crt 644 && changed=true
if [ "$changed" = true ] && { unit_active databasify-postgres || unit_active databasify-patroni; }; then
    pg postgres > /dev/null <<'SQL'
SELECT pg_reload_conf()
SQL
    unit_do reload databasify-pgbouncer
fi
echo "Installed the TLS certificate."
