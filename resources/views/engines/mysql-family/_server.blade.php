SERVER_UNIT={!! $sh->arg($engine->serverUnit()) !!}

serving() {
    my_try '' <<< 'SELECT 1'
    [ -z "$MY_ERROR" ]
}

restarts() {
    databasify ctl show -p NRestarts --value "$SERVER_UNIT" 2> /dev/null || echo 0
}

wait_ready() {
    phase "waiting for $1"
    READY_LABEL=$1
    READY_BASELINE=$(restarts)
    wait_until 21600 2 ready_check || fail_task 'the server was not ready within 6h0m0s'
}

ready_check() {
    if [ $(( $(restarts) - READY_BASELINE )) -gt 3 ]; then
        fail_task "$READY_LABEL keeps stopping while it starts, see its log"
    fi
    serving
}
