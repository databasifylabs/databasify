@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rewrite')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mysql-family._keys')
@include('engines.mariadb._state')
@include('engines.mariadb._galera')
@include('engines.mariadb._remote')
@include('engines.mariadb._dump')
@include('engines.mariadb._replica')

seed_attempt() {
    copy_from "$WRITER" 25060 databasify_repl "$REPLICATION_SECRET" true true || return 1
    if [ -z "$COPIED_GTID" ]; then
        ATTEMPT_ERROR='the copy from the writer carried no GTID position'
        return 1
    fi
    my_try '' <<SQL
SET GLOBAL gtid_slave_pos = $(my_string "$COPIED_GTID")
SQL
    if [ -n "$MY_ERROR" ]; then
        ATTEMPT_ERROR=$MY_ERROR_TEXT
        return 1
    fi
}

require_saved
if saved_is '.readOnly'; then
    writer_to_follow
    replication_password
    position=$(my '' <<'SQL'
@verbatim
SELECT @@gtid_slave_pos
@endverbatim
SQL
)
    if [ -z "$position" ]; then
        phase 'copying'
        retry_or_fail 600 10m0s seed_attempt
    fi
    phase 'following'
    follow_writer "$WRITER" "$REPLICATION_SECRET"
    : | write_file "$ROOT/state/replica" 600 false || true
    exit 0
fi
if ! saved_is '.clustered and (.readOnly | not)'; then
    fail_task 'run node configure with clustered on first'
fi
seed=false
if saved_is '.primary == null or .primary == .ip'; then
    seed=true
fi
if read_state && [ "$STATE_GALERA" = 1 ] && state_synced; then
    if [ "$seed" = true ]; then
        become_writer_if_recorded "$(saved .ip)"
    fi
    exit 0
fi
require_primary_keys
if [ "$seed" = true ]; then
    phase 'bootstrapping'
    mark_new_cluster
else
    phase 'joining'
fi
restart_server
phase 'syncing'
wait_until 21600 2 synced_now || fail_task 'the server was not ready within 6h0m0s'
clear_new_cluster
if [ "$seed" = true ]; then
    become_writer_if_recorded "$(saved .ip)"
fi
