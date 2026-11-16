@include('engines.sentinel._cli')
replication=$(local_cli INFO replication)
server=$(local_cli INFO server)
output=$(jq -cn --arg replication "$replication" --arg server "$server" '{replication: $replication, server: $server}')
result "$output"
