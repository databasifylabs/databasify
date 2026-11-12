@include('engines.postgres._psql')
@include('engines.postgres._accounts')
recovery=$(pg postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
)
if [ "$recovery" = t ]; then
    result '{}'
    exit 0
fi
DATABASIFY_VAR_postgres_password="$ADMIN_PASSWORD" DATABASIFY_VAR_replication_password="$REPLICATION_PASSWORD" DATABASIFY_VAR_pgbouncer_password="$POOLER_PASSWORD" pg postgres > /dev/null <<'SQL'
SET log_min_duration_statement = -1; SET log_statement = 'none'; SET log_min_error_statement = panic;
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
if [ -n "$ACCOUNT_DATABASE" ]; then
    DATABASIFY_VAR_name="$ACCOUNT_DATABASE" pg postgres > /dev/null <<'SQL'
SELECT NOT EXISTS (SELECT FROM pg_database WHERE datname = :'name') AS created \gset
\if :created
CREATE DATABASE :"name";
\endif
SQL
    NAME=$ACCOUNT_USER
    GRANTS=$(jq -cn --arg db "$ACCOUNT_DATABASE" '[{database: $db, access: "write"}]')
    DATABASIFY_VAR_name="$ACCOUNT_USER" DATABASIFY_VAR_password="$ACCOUNT_PASSWORD" pg postgres > /dev/null <<'SQL'
SET log_min_duration_statement = -1; SET log_statement = 'none'; SET log_min_error_statement = panic;
SELECT NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'name') AS created \gset
\if :created
CREATE ROLE :"name" LOGIN;
\endif
ALTER ROLE :"name" LOGIN PASSWORD :'password';
SQL
    apply_grants
fi
result '{}'
