@include('ssh.engines.postgres.helpers')
as_postgres psql -X -q -v ON_ERROR_STOP=1 -h {{ $socketDirectory }} -p {{ $clientPort }} -d postgres \
    -v postgres_password="$(echo '{{ base64_encode($secrets['POSTGRES_PASSWORD'] ?? '') }}' | base64 -d)" \
    -v replication_password="$(echo '{{ base64_encode($secrets['REPLICATION_PASSWORD'] ?? '') }}' | base64 -d)" \
    -v pgbouncer_password="$(echo '{{ base64_encode($secrets['PGBOUNCER_AUTH_PASSWORD'] ?? '') }}' | base64 -d)" > /dev/null <<'SQL'
ALTER ROLE postgres PASSWORD :'postgres_password';
SELECT 'CREATE ROLE replicator WITH REPLICATION LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'replicator') \gexec
ALTER ROLE replicator WITH REPLICATION LOGIN PASSWORD :'replication_password';
SELECT 'CREATE ROLE pgbouncer LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'pgbouncer') \gexec
ALTER ROLE pgbouncer LOGIN PASSWORD :'pgbouncer_password';
CREATE SCHEMA IF NOT EXISTS pgbouncer AUTHORIZATION postgres;
CREATE OR REPLACE FUNCTION pgbouncer.get_auth(p_usename text)
    RETURNS TABLE (username text, password text)
    LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog
    AS 'SELECT usename::text, passwd::text FROM pg_catalog.pg_shadow WHERE usename = p_usename';
REVOKE ALL ON FUNCTION pgbouncer.get_auth(text) FROM PUBLIC;
GRANT USAGE ON SCHEMA pgbouncer TO pgbouncer;
GRANT EXECUTE ON FUNCTION pgbouncer.get_auth(text) TO pgbouncer;
SQL
echo '{{ base64_encode($sql) }}' | pg_from > /dev/null
echo "database {{ $schema }} and user {{ $username }} ready"
