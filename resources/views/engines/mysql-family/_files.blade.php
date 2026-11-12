ROOT={!! $sh->arg($engine->home) !!}

engine_directory() {
    mkdir -p -m 750 "$ROOT/$1"
    chown 64999:64999 "$ROOT/$1"
}

write_file() {
    local path=$1 mode=$2 owned=$3 content current
    content=$(cat; printf x)
    content=${content%x}
    if [ -f "$path" ]; then
        current=$(cat "$path"; printf x)
        if [ "$current" = "${content}x" ]; then
            return 1
        fi
    fi
    printf '%s' "$content" > "$path.tmp"
    chmod "$mode" "$path.tmp"
    if [ "$owned" = true ]; then
        chown 64999:64999 "$path.tmp"
    fi
    mv "$path.tmp" "$path"
}
