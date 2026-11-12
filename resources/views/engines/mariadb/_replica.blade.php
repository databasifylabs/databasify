slave_running() {
    local running
    running=$(my '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'SLAVE_RUNNING'") || return 1
    [ "$running" = ON ]
}

follow_writer() {
    my '' > /dev/null <<SQL
STOP SLAVE;
CHANGE MASTER TO MASTER_HOST = $(my_string "$1"), MASTER_PORT = 25060, MASTER_USER = 'databasify_repl', MASTER_PASSWORD = $(my_string "$2"), MASTER_SSL = 1, MASTER_USE_GTID = slave_pos;
START SLAVE
SQL
    wait_until 600 2 slave_running || fail_task 'the server was not ready within 10m0s'
}

writer_to_follow() {
    WRITER=$(saved '.primary // empty')
    if [ -z "$WRITER" ]; then
        fail_task 'a read-only node needs the writer to follow'
    fi
}

stop_following() {
    my '' > /dev/null <<'SQL'
STOP SLAVE;
RESET SLAVE ALL;
SET GLOBAL read_only = OFF
SQL
    rm -f "$ROOT/state/replica"
}
