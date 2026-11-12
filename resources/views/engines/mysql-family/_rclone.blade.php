remote_of() {
    local joined="${STORAGE_PATH}/${1#/}"
    joined=${joined#/}
    joined=${joined%/}
    printf '%s%s' "$RCLONE_REMOTE" "$joined"
}

rclone_list() {
    local output errors
    errors=$(mktemp)
    if output=$(rclone lsjson "$(remote_of "$1")" 2> "$errors"); then
        rm -f "$errors"
        printf '%s' "${output:-[]}"
        return 0
    fi
    if grep -q 'directory not found' "$errors"; then
        rm -f "$errors"
        printf '[]'
        return 0
    fi
    RCLONE_ERROR="rclone lsjson: $(tr -s '[:space:]' ' ' < "$errors")"
    rm -f "$errors"
    return 1
}
