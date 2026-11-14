@include('engines.mysql-family._mysql')
@include('engines.mysql-family._version')
@include('engines.mysql._role')

role=$(node_role)
status_result "$role"
