@include('ssh.engines.mysql.helpers')
msql "ALTER INSTANCE RELOAD TLS" > /dev/null
