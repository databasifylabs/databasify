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
    printf '%s' "$content" > "$path.tmp" || databasify_fail internal "could not write $path"
    chmod "$mode" "$path.tmp" || databasify_fail internal "could not set the mode of $path"
    if [ "$owned" = true ]; then
        chown 64999:64999 "$path.tmp" || databasify_fail internal "could not own $path"
    fi
    mv "$path.tmp" "$path" || databasify_fail internal "could not replace $path"
}
