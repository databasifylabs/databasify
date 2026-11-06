@include('ssh.engines.postgres.helpers')
@if ($clustered)
databasify ctl reload databasify-patroni
@else
pg "SELECT pg_reload_conf()" > /dev/null
@endif
echo "settings applied"
