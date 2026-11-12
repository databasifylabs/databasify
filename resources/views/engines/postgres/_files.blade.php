as_postgres() {
    HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups "$@"
}

ensure_directory() {
    if [ ! -e "$1" ]; then
        mkdir -p -m 750 "$1"
        chown 64999:64999 "$1"
    fi
}

write_file() {
    local path=$1 mode=$2 content current
    content=$(cat; printf x)
    content=${content%x}
    if [ -f "$path" ]; then
        current=$(cat "$path"; printf x)
        if [ "$current" = "${content}x" ]; then
            return 1
        fi
    fi
    ensure_directory "$(dirname "$path")"
    printf '%s' "$content" > "$path.tmp"
    chmod "$mode" "$path.tmp"
    chown 64999:64999 "$path.tmp"
    mv "$path.tmp" "$path"
}

pools_ini() {
    jq -r '.[] | "\(.name) = host=127.0.0.1 port=25060 dbname=\(.database) pool_size=\(.size) pool_mode=\(.mode)"' /srv/postgres/conf/pools.json 2> /dev/null || true
}

default_pool_size() {
    local size=$(( $(nproc) * 2 ))
    if [ "$size" -lt 10 ]; then size=10; fi
    if [ "$size" -gt 100 ]; then size=100; fi
    echo "$size"
}
