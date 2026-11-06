registry_reachable() {
    curl -sS -o /dev/null --connect-timeout 5 --max-time 10 "https://${1%%/*}/v2/"
}
pull_image() {
    attempt=1
    until registry_reachable "$1" && timeout 900 docker pull -q "$1" > /dev/null; do
        [ "$attempt" -lt 4 ] || { echo "could not pull $1, check that this server reaches ${1%%/*}"; return 1; }
        echo "retrying the pull in $((attempt * 5))s"
        sleep $((attempt * 5))
        attempt=$((attempt + 1))
    done
}
require_wrapper() {
    wrapper=$(docker image inspect --format '@{{ index .Config.Labels "io.databasify.wrapper" }}' "$1" 2> /dev/null || true)
    if [ -n "$wrapper" ] && [ "$(printf '%s\n%s\n' '{{ $minimumWrapper }}' "$wrapper" | sort -V | head -n 1)" = '{{ $minimumWrapper }}' ]; then
        return 0
    fi
    echo "$1 runs databasify ${wrapper:-without a version}, but this Databasify needs {{ $minimumWrapper }} or newer; publish newer engine images first."
    return 1
}
