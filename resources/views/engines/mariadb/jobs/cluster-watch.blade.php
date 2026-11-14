set -uo pipefail
@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mariadb._state')
@include('engines.mariadb._remote')
WATCH_INTERVAL=10
DISCONNECTED_CHECKS=3
REPOINT_TIMEOUT=60
child=''
trap '[ -z "$child" ] || kill "$child" 2> /dev/null; exit 0' TERM INT

pause() {
    sleep "$1" &
    child=$!
    wait "$child"
    child=''
}

hold_read_only() {
    if [ "$1" = true ]; then
        state_read_only || my_try '' <<< 'SET GLOBAL read_only = ON'
    else
        ! state_read_only || my_try '' <<< 'SET GLOBAL read_only = OFF'
    fi
}

live_addresses() {
    tr ',' '\n' <<< "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
        | sed -n -e 's/^\([0-9]\{1,3\}\(\.[0-9]\{1,3\}\)\{3\}\):[0-9]\{1,5\}$/\1/p'
}

lowest_address() {
    sort -t . -k 1,1n -k 2,2n -k 3,3n -k 4,4n <<< "$1" | grep . | head -n 1
}

keep_writer_role() {
    local self=$1 writer live
    read_state && state_synced || return 0
    my_try '' <<< 'SELECT ip FROM databasify.writer WHERE id = 1'
    [ -z "$MY_ERROR" ] || return 0
    writer=$MY_OUTPUT
    if [ -z "$writer" ]; then
        hold_read_only true
        return 0
    fi
    my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_INCOMING_ADDRESSES'"
    live=$(live_addresses "$MY_OUTPUT")
    if ! grep -qxF -- "$writer" <<< "$live"; then
        [ "$(lowest_address "$live")" = "$self" ] || return 0
        my_try '' <<< "REPLACE INTO databasify.writer VALUES (1, $(my_string "$self"))"
        [ -z "$MY_ERROR" ] || return 0
        writer=$self
    fi
    if [ "$writer" = "$self" ]; then
        hold_read_only false
    else
        hold_read_only true
    fi
}

current_source() {
    MY_HEADERS=1 my_try '' <<< 'SHOW SLAVE STATUS'
    [ -z "$MY_ERROR" ] || return 0
    awk -F '\t' 'NR == 1 { for (i = 1; i <= NF; i++) if ($i == "Master_Host") column = i } NR == 2 && column { print $column }' <<< "$MY_OUTPUT"
}

synced_member() {
    local cnf state
    cnf=$(remote_cnf "$1" "$2")
    state=$("$MY_CLIENT" --defaults-extra-file="$cnf" -N -B -e "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_LOCAL_STATE_COMMENT'" 2> /dev/null)
    rm -f "$cnf"
    [ "$state" = Synced ]
}

repoint_to() {
    local deadline
    my_try '' <<SQL
STOP SLAVE;
CHANGE MASTER TO MASTER_HOST = $(my_string "$1"), MASTER_PORT = 25060, MASTER_USER = 'databasify_repl', MASTER_PASSWORD = $(my_string "$2"), MASTER_SSL = 1, MASTER_USE_GTID = slave_pos;
START SLAVE
SQL
    [ -z "$MY_ERROR" ] || return 0
    deadline=$(( $(date +%s) + REPOINT_TIMEOUT ))
    until [ "$(date +%s)" -ge "$deadline" ]; do
        my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'SLAVE_RUNNING'"
        [ "$MY_OUTPUT" != ON ] || return 0
        pause 2
    done
}

keep_following() {
    local password current source='' ip
    my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'SLAVE_RUNNING'"
    if [ -n "$MY_ERROR" ] || [ "$MY_OUTPUT" = ON ]; then
        missed=0
        return 0
    fi
    missed=$(( missed + 1 ))
    [ "$missed" -ge "$DISCONNECTED_CHECKS" ] || return 0
    missed=0
    password=$(saved '.secrets.replication // empty')
    [ -n "$password" ] || return 0
    current=$(current_source)
    for ip in $(saved '.members[] | select(.voting) | .ip'); do
        if [ "$ip" != "$current" ] && synced_member "$ip" "$password"; then
            source=$ip
            break
        fi
    done
    [ -z "$source" ] || repoint_to "$source" "$password"
}

missed=0
while true; do
    if [ -f "$ROOT/state/node.json" ]; then
        if saved_is '.clustered and (.readOnly | not)'; then
            keep_writer_role "$(saved '.ip')"
        fi
        if saved_is '.readOnly' && [ -e "$ROOT/state/replica" ]; then
            keep_following
        fi
    fi
    pause "$WATCH_INTERVAL"
done
