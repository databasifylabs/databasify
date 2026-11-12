node_state() {
    my '' <<'SQL'
@verbatim
SELECT @@wsrep_on, @@read_only,
    COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_CLUSTER_STATUS'), ''),
    COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_LOCAL_STATE_COMMENT'), ''),
    COALESCE((SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'SLAVES_RUNNING'), '0')
@endverbatim
SQL
}

read_state() {
    IFS=$'\t' read -r STATE_GALERA STATE_READ_ONLY STATE_CLUSTER STATE_LOCAL STATE_REPLICATING <<< "$(node_state)"
}

state_synced() {
    [ "$STATE_CLUSTER" = Primary ] && [ "$STATE_LOCAL" = Synced ]
}

state_read_only() {
    [ "$STATE_READ_ONLY" != 0 ] && [ "$(tr '[:lower:]' '[:upper:]' <<< "$STATE_READ_ONLY")" != OFF ]
}

role_of_state() {
    if [ "$STATE_GALERA" = 1 ] && ! state_synced; then
        echo unknown
    elif ! state_read_only; then
        echo primary
    elif [ "$STATE_GALERA" = 1 ] || [ "$STATE_REPLICATING" != 0 ]; then
        echo replica
    else
        echo unknown
    fi
}
