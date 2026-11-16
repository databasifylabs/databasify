ALTER ROLE postgres PASSWORD {!! $sql->string($passwords['admin']) !!};
SELECT 'CREATE ROLE replicator WITH REPLICATION LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'replicator') \gexec
ALTER ROLE replicator WITH REPLICATION LOGIN PASSWORD {!! $sql->string($passwords['replication']) !!};
SELECT 'CREATE ROLE pgbouncer LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'pgbouncer') \gexec
ALTER ROLE pgbouncer LOGIN PASSWORD {!! $sql->string($passwords['pooler']) !!};
CREATE SCHEMA IF NOT EXISTS pgbouncer AUTHORIZATION postgres;
CREATE OR REPLACE FUNCTION pgbouncer.get_auth(p_usename text)
    RETURNS TABLE (username text, password text)
    LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog
    AS 'SELECT usename::text, passwd::text FROM pg_catalog.pg_shadow WHERE usename = p_usename';
REVOKE ALL ON FUNCTION pgbouncer.get_auth(text) FROM PUBLIC;
GRANT USAGE ON SCHEMA pgbouncer TO pgbouncer;
GRANT EXECUTE ON FUNCTION pgbouncer.get_auth(text) TO pgbouncer;
@if ($account !== null)
@include('engines.postgres.accounts.create-database', ['name' => $account->database])
@include('engines.postgres.accounts.create-user', ['name' => $account->user, 'password' => $account->password])
@endif
