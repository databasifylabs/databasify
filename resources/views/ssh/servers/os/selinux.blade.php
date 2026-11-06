selinux_restore() {
    command -v restorecon > /dev/null 2>&1 || return 0
    restorecon -R "$@"
}

selinux_label() {
    command -v selinuxenabled > /dev/null 2>&1 && selinuxenabled || return 0
    chcon -t "$1" "$2"
}
