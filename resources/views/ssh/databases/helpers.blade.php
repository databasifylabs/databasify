dir={{ $directory }}
processes='{{ implode(' ', $processes) }}'
wait_for() {
    deadline=$(( $(date +%s) + $1 ))
    shift
    until "$@"; do
        [ "$(date +%s)" -lt "$deadline" ] || { echo "timed out waiting"; return 1; }
        sleep 3
    done
}
retry() {
    attempt=1
    until "$@"; do
        [ "$attempt" -lt 5 ] || return 1
        echo "retrying in $((attempt * 5))s"
        sleep $((attempt * 5))
        attempt=$((attempt + 1))
    done
}
put_file() {
    tmp=$(mktemp)
    cat > "$tmp"
    install -D -m "$2" -o "$3" -g "${4:-$3}" "$tmp" "$1"
    rm -f "$tmp"
}
