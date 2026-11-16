@include('engines.sentinel._cli')
info=$(local_cli INFO)
output=$(jq -cn --arg info "$info" '{info: $info}')
result "$output"
