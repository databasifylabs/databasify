@include('engines.sentinel._cli')
@include('engines.sentinel._node')
cli_try sentinel_cli SENTINEL MASTER main
master=$CLI_OUTPUT
serving=false
sentinels=''
if serves_as_primary "$(pair_value "$master" ip)"; then
    cli_try sentinel_cli SENTINEL SENTINELS main
    if [ -z "$CLI_ERROR" ]; then
        serving=true
        sentinels=$CLI_OUTPUT
    fi
fi
output=$(jq -cn --arg master "$master" --argjson serving "$serving" --arg sentinels "$sentinels" '{master: $master, serving: $serving, sentinels: $sentinels}')
result "$output"
