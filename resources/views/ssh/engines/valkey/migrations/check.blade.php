export VALKEYCLI_AUTH
VALKEYCLI_AUTH=$(echo '{{ base64_encode($password) }}' | base64 -d)
info=$(timeout 15 {{ $cli }} -h {{ $host }} -p {{ $port }} {!! $username !== '' ? '--user '.escapeshellarg($username) : '' !!} {{ $tls ? '--tls --insecure' : '' }} --no-auth-warning INFO </dev/null 2>&1 | tr -d '\r' || true)
if ! grep -qE '^(redis|valkey)_version:' <<< "$info"; then
    printf 'error=%s\n' "$(head -n 3 <<< "$info" | tr '\n' ' ')"
    exit 0
fi
grep -E '^(redis_version|valkey_version|used_memory_human|role|maxmemory_policy):' <<< "$info" | sed 's/:/=/'
awk -F '[=,:]' '/^db[0-9]+:/ { total += $3 } END { print "keys=" total + 0 }' <<< "$info"
