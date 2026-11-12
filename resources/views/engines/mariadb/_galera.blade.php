ensure_writer_table() {
    my '' > /dev/null <<'SQL'
CREATE DATABASE IF NOT EXISTS databasify;
CREATE TABLE IF NOT EXISTS databasify.writer (id TINYINT PRIMARY KEY, ip VARCHAR(45) NOT NULL)
SQL
}

become_writer_if_recorded() {
    local ip=$1 writer
    ensure_writer_table
    my '' > /dev/null <<SQL
INSERT IGNORE INTO databasify.writer VALUES (1, $(my_string "$ip"))
SQL
    writer=$(my '' <<< 'SELECT ip FROM databasify.writer WHERE id = 1')
    if [ "$writer" = "$ip" ]; then
        my '' > /dev/null <<< 'SET GLOBAL read_only = OFF'
    fi
}

hold_read_only() {
    local wanted=$1 current=false
    if state_read_only; then
        current=true
    fi
    if [ "$wanted" = "$current" ]; then
        return 0
    fi
    if [ "$wanted" = true ]; then
        my_try '' <<< 'SET GLOBAL read_only = ON'
    else
        my_try '' <<< 'SET GLOBAL read_only = OFF'
    fi
}

addresses_in() {
    local address host
    tr ',' '\n' <<< "$1" | while IFS= read -r address; do
        address=$(tr -d '[:space:]' <<< "$address")
        if [[ $address =~ ^\[([0-9a-fA-F:]+)\]:[0-9]+$ ]]; then
            echo "${BASH_REMATCH[1]}"
        elif [[ $address =~ ^([0-9]{1,3}(\.[0-9]{1,3}){3}):[0-9]+$ ]]; then
            echo "${BASH_REMATCH[1]}"
        fi
    done
}

lowest_of() {
    sort -t . -k1,1n -k2,2n -k3,3n -k4,4n <<< "$1" | sed -n '1p'
}

keep_writer_role() {
    local ip=$1 writer live
    synced_now || return 0
    my_try '' <<< 'SELECT ip FROM databasify.writer WHERE id = 1'
    if [ -n "$MY_ERROR" ]; then
        return 0
    fi
    writer=$MY_OUTPUT
    if [ -z "$writer" ]; then
        hold_read_only true
        return 0
    fi
    my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_INCOMING_ADDRESSES'"
    live=$(addresses_in "$MY_OUTPUT")
    if ! grep -qxF -- "$writer" <<< "$live"; then
        if [ "$(lowest_of "$live")" != "$ip" ]; then
            return 0
        fi
        my_try '' <<SQL
REPLACE INTO databasify.writer VALUES (1, $(my_string "$ip"))
SQL
        if [ -n "$MY_ERROR" ]; then
            return 0
        fi
        writer=$ip
    fi
    if [ "$writer" != "$ip" ]; then
        hold_read_only true
    else
        hold_read_only false
    fi
}

clear_new_cluster() {
    if [ ! -e "$ROOT/state/new-cluster" ]; then
        return 0
    fi
    rm -f "$ROOT/state/new-cluster"
    if [ -n "$MANIFEST" ]; then
        write_manifest || true
    fi
    printf '{}' | databasify service reload > /dev/null
}

mark_new_cluster() {
    : | write_file "$ROOT/state/new-cluster" 600 false || true
    write_manifest || true
}

restart_server() {
    printf '{}' | databasify service reload > /dev/null
    unit_do restart mariadb-server
}
