backrest() {
    local config=$1
    shift
    HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups timeout 86400 pgbackrest --config="$config" --stanza=main --log-level-console=off "$@"
}
