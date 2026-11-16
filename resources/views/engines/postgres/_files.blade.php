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
