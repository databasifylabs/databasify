flavor=''
for candidate in valkey redis; do
    if [ -r "/srv/$candidate/conf/password" ]; then
        flavor=$candidate
    fi
done
if [ -z "$flavor" ]; then
    printf 'total=0\nonline=0\n'
    exit 0
fi
cli=$(command -v "$flavor-cli" || true)
ask() { { REDISCLI_AUTH=$(cat "/srv/$flavor/conf/password") VALKEYCLI_AUTH=$(cat "/srv/$flavor/conf/password") timeout 5 "$cli" -h "$1" -p "$2" "${@:3}" </dev/null 2>/dev/null || true; } | tr -d '\r'; }
primary=$(ask 127.0.0.1 {{ $sentinelPort }} SENTINEL get-master-addr-by-name {{ $master }} | head -n 1)
others=$(ask 127.0.0.1 {{ $sentinelPort }} SENTINEL master {{ $master }} | paste - - | awk -F '\t' '$1 == "num-other-sentinels" { print $2 }')
total=$(( ${others:-0} + 1 ))
echo "total=$total"
if [ -n "$primary" ] && [ "$(ask "$primary" {{ $dataPort }} ROLE | head -n 1)" = master ]; then
    echo "online=$total"
else
    echo "online=0"
fi
