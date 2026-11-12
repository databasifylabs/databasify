@include('engines.mysql-family._mysql')
@include('engines.mariadb._state')
@include('engines.mysql-family._version')

read_state
status_result "$(role_of_state)"
