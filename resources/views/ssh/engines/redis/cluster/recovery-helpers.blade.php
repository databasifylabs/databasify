@include('ssh.engines.redis.helpers')
sentinel_conf={{ $directory }}/sentinel/sentinel.conf
ask() { { REDISCLI_AUTH=$(cat {{ $directory }}/conf/password) timeout 5 {{ $cli }} -h "$1" -p "$2" "${@:3}" </dev/null 2>/dev/null || true; } | tr -d '\r'; }
sentinel_hosts() { { echo 127.0.0.1; sed -n 's/^sentinel known-sentinel {{ $master }} \([^ ]*\) .*/\1/p' "$sentinel_conf" 2> /dev/null || true; } | sort -u; }
sentinel_view() { ask "$1" {{ $sentinelPort }} SENTINEL master {{ $master }} | paste - - | awk -F '\t' -v key="$2" '$1 == key { print $2 }'; }
serves_as_primary() { [ "$(ask "$1" {{ $dataPort }} ROLE | head -n 1)" = master ]; }
newest_primary() {
    best=''
    best_epoch=-1
    for host in $(sentinel_hosts); do
        address=$(sentinel_view "$host" ip)
        epoch=$(sentinel_view "$host" config-epoch)
        if [ -n "$address" ] && [ "${epoch:--1}" -gt "$best_epoch" ]; then
            best=$address
            best_epoch=$epoch
        fi
    done
    [ -n "$best" ] && serves_as_primary "$best" && echo "$best"
}
highest_epoch() {
    {
        sed -n -e 's/^sentinel current-epoch \([0-9]*\)$/\1/p' -e 's/^sentinel config-epoch {{ $master }} \([0-9]*\)$/\1/p' "$sentinel_conf" 2> /dev/null || true
        for host in $(sentinel_hosts); do sentinel_view "$host" config-epoch; done
        echo 0
    } | grep -E '^[0-9]+$' | sort -n | tail -n 1
}
