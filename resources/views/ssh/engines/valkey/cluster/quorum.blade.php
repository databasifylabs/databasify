password={{ $directory }}/conf/password
if [ ! -r "$password" ]; then
    printf 'total=0\nonline=0\n'
    exit 0
fi
ask() { { VALKEYCLI_AUTH=$(cat "$password") timeout 5 {{ $cli }} -h "$1" -p "$2" "${@:3}" </dev/null 2>/dev/null || true; } | tr -d '\r'; }
primary=$(ask 127.0.0.1 {{ $sentinelPort }} SENTINEL get-master-addr-by-name {{ $master }} | head -n 1)
others=$(ask 127.0.0.1 {{ $sentinelPort }} SENTINEL master {{ $master }} | paste - - | awk -F '\t' '$1 == "num-other-sentinels" { print $2 }')
reachable=$(ask 127.0.0.1 {{ $sentinelPort }} SENTINEL sentinels {{ $master }} | paste - - | awk -F '\t' '$1 == "flags" && $2 !~ /s_down|o_down|disconnected/ { count++ } END { print count + 0 }')
total=$(( ${others:-0} + 1 ))
echo "total=$total"
if [ -n "$primary" ] && [ "$(ask "$primary" {{ $dataPort }} ROLE | head -n 1)" = master ]; then
    echo "online=$(( reachable + 1 ))"
else
    echo "online=0"
fi
