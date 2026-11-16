revoke_ignoring_missing() {
    my_try '' <<< "$1"
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 1141 '* ]]; then
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
}
