@include('engines.sentinel._cli')
@include('engines.sentinel._node')
drop_clients() {
    local port=$1 list id
    cli_try cli 127.0.0.1 "$port" CLIENT LIST
    [ -n "$CLI_ERROR" ] && return 0
    list=$CLI_OUTPUT
    while IFS= read -r id; do
        [ -z "$id" ] && continue
        cli_try cli 127.0.0.1 "$port" CLIENT KILL ID "$id"
    done < <(awk -v port="$port" -v keep=" $KEEP " '{
        id = ""; addr = ""; laddr = ""
        for (i = 1; i <= NF; i++) {
            split($i, pair, "=")
            if (pair[1] == "id") id = pair[2]
            if (pair[1] == "addr") addr = substr($i, 6)
            if (pair[1] == "laddr") laddr = substr($i, 7)
        }
        host = addr; sub(/:[^:]*$/, "", host)
        local_port = laddr; sub(/.*:/, "", local_port)
        if (id != "" && local_port == port && index(keep, " " host " ") == 0) print id
    }' <<< "$list")
}
drop_removed() {
    drop_clients 6379
    drop_clients 26379
    cli_try sentinel_cli SENTINEL RESET main
}
peers_settled() {
    local seen
    cli_try sentinel_cli SENTINEL SENTINELS main
    [ -z "$CLI_ERROR" ] || return 1
    seen=$(grep -cx 'flags' <<< "$CLI_OUTPUT" || true)
    if [ "$seen" -gt "$PEERS" ]; then
        drop_removed
        return 1
    fi
    [ "$seen" -ge "$PEERS" ]
}
drop_removed
wait_until 120 2 peers_settled || databasify_fail internal "$FLAVOR did not become ready within 2m0s"
result '{}'
