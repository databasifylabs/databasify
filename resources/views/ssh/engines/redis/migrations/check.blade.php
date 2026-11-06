export VALKEYCLI_AUTH REDISCLI_AUTH
VALKEYCLI_AUTH=$(echo '{{ base64_encode($password) }}' | base64 -d)
REDISCLI_AUTH=$VALKEYCLI_AUTH
source_info() { timeout 15 {{ $cli }} -h {{ $host }} -p {{ $port }} {!! $username !== '' ? '--user '.escapeshellarg($username) : '' !!} {{ $tls ? '--tls --insecure' : '' }} --no-auth-warning INFO "$@" </dev/null 2>&1 | tr -d '\r' || true; }
info=$(source_info)
if ! grep -qE '^(redis|valkey)_version:' <<< "$info"; then
    printf 'error=%s\n' "$(head -n 3 <<< "$info" | tr '\n' ' ')"
    exit 0
fi
grep -E '^(redis_version|valkey_version|used_memory_human|role|maxmemory_policy):' <<< "$info" | sed 's/:/=/'
awk -F '[=,:]' '/^db[0-9]+:/ { total += $3 } END { print "keys=" total + 0 }' <<< "$info"
printf 'modules=%s\n' "$({ grep -oE '^module:name=[^,]+' <<< "$info" || true; } | sed 's/^module:name=//' | paste -sd, -)"
if grep -q '^module:name=search,' <<< "$info"; then
    { grep -E '^search_number_of_indexes:' <<< "$(source_info search_indexes)" || true; } | sed 's/:/=/'
fi
