@include('engines.sentinel._cli')
stats=$(local_cli INFO all)
number() {
    local value
    value=$(info_field "$stats" "$1")
    [[ $value =~ ^-?[0-9]+$ ]] && printf '%s' "$value" || printf 0
}
memory=$(number used_memory)
result "$(jq -cn --argjson connections "$(number connected_clients)" --argjson queries "$(number instantaneous_ops_per_sec)" --argjson data "$(( memory / 1048576 ))" '{connections: $connections, queries: $queries, dataMb: $data}')"
