@include('ssh.engines.postgres.helpers')
pg "SELECT pg_reload_conf()" > /dev/null
databasify ctl reload databasify-pgbouncer
