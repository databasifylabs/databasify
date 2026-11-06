@include('ssh.engines.postgres.helpers')
pg "SELECT 'user:' || rolname FROM pg_roles UNION ALL SELECT 'database:' || datname FROM pg_database WHERE NOT datistemplate"
