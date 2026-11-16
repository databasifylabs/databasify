backrest() {
    local config=$1
    shift
    as_postgres timeout 86400 pgbackrest --config="$config" --stanza=main --log-level-console=off "$@"
}
