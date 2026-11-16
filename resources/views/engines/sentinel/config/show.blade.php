@include('engines.sentinel._cli')
config=$(local_cli CONFIG GET {!! collect($names)->map($sh->arg(...))->implode(' ') !!})
output=$(jq -cn --arg config "$config" '{config: $config}')
result "$output"
