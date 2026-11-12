@include('engines.sentinel._cli')
@include('engines.sentinel._node')
if [ ! -f "$ROOT/conf/password" ]; then
    result '{"total":0,"online":0}'
    exit 0
fi
cli_try sentinel_cli SENTINEL MASTER main
if [ -n "$CLI_ERROR" ]; then
    result '{"total":0,"online":0}'
    exit 0
fi
master=$CLI_OUTPUT
others=$(pair_value "$master" num-other-sentinels)
[[ $others =~ ^[0-9]+$ ]] || others=0
total=$(( others + 1 ))
online=0
if serves_as_primary "$(pair_value "$master" ip)"; then
    cli_try sentinel_cli SENTINEL SENTINELS main
    if [ -z "$CLI_ERROR" ]; then
        online=$(( 1 + $(awk '$0 == "flags" { getline; if ($0 !~ /s_down|o_down|disconnected/) count++ } END { print count + 0 }' <<< "$CLI_OUTPUT") ))
    fi
fi
result "{\"total\":$total,\"online\":$online}"
