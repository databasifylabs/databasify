@include('engines.mysql-family._mysql')
@include('engines.mariadb._state')
@include('engines.mysql-family._version')

read_state || databasify_fail "${MY_ERROR:-internal}" "${MY_ERROR_TEXT:-the node state query returned nothing}"
status_result "$(role_of_state)"
