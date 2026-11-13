@include('engines.mysql-family._mysql')
@include('engines.mysql-family._server')
unit_do restart "$SERVER_UNIT"
echo "Restarted the server."
