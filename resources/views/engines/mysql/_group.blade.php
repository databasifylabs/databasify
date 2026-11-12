start_group() {
    my_try '' <<SQL
START GROUP_REPLICATION USER = 'databasify_repl', PASSWORD = $(my_string "$1")
SQL
    [ -z "$MY_ERROR" ] || [[ $MY_ERROR_TEXT == 'Error 3093 '* ]]
}

online_check() {
    my_try '' <<'SQL'
@verbatim
SELECT MEMBER_STATE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid
@endverbatim
SQL
    if [ -n "$MY_ERROR" ]; then
        return 1
    fi
    if [ "$MY_OUTPUT" = ERROR ]; then
        fail_task 'this member left the group with an error, see the MySQL log'
    fi
    if { [ -z "$MY_OUTPUT" ] || [ "$MY_OUTPUT" = OFFLINE ]; } && [ -n "$GROUP_PASSWORD" ]; then
        start_group "$GROUP_PASSWORD" || true
        return 1
    fi
    [ "$MY_OUTPUT" = ONLINE ]
}

boot_read_only() {
    engine_directory conf/conf.d
    printf '[mysqld]\nsuper_read_only=ON\n' | write_file "$ROOT/conf/conf.d/grouped.cnf" 600 true || true
}

online_and_joined() {
    GROUP_PASSWORD=$1
    wait_until 1800 2 online_check || fail_task 'the server was not ready within 30m0s'
    boot_read_only
    : | write_file "$ROOT/state/grouped" 600 false || true
}
