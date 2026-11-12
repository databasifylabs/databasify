@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._server')
@include('engines.mysql._group')
@include('engines.mysql-family._keys')

clone_attempt() {
    my_try '' <<SQL
SET GLOBAL clone_valid_donor_list = $(my_string "$PRIMARY:25060")
SQL
    if [ -n "$MY_ERROR" ]; then
        ATTEMPT_ERROR=$MY_ERROR_TEXT
        return 1
    fi
    my_try '' <<SQL
CLONE INSTANCE FROM 'databasify_repl'@$(my_string "$PRIMARY"):25060 IDENTIFIED BY $(my_string "$REPLICATION_SECRET") REQUIRE SSL
SQL
    if [[ $MY_ERROR_TEXT == 'Error 3707 '* ]]; then
        unit_do restart "$SERVER_UNIT"
    elif [ -n "$MY_ERROR" ] && [ "$MY_ERROR" != unavailable ]; then
        ATTEMPT_ERROR=$MY_ERROR_TEXT
        return 1
    fi
    wait_until 21600 2 serving || {
        ATTEMPT_ERROR='the server was not ready within 6h0m0s'
        return 1
    }
}

follow_group() {
    PRIMARY=$(saved '.primary // empty')
    if [ -z "$PRIMARY" ]; then
        fail_task 'a read-only node needs the primary to follow'
    fi
    if [ -z "$(gtid_executed)" ]; then
        phase 'cloning'
        retry_or_fail 1800 30m0s clone_attempt
    fi
    phase 'following'
    my '' > /dev/null <<SQL
CHANGE REPLICATION SOURCE TO SOURCE_HOST = $(my_string "$PRIMARY"), SOURCE_PORT = 25060, SOURCE_USER = 'databasify_repl', SOURCE_PASSWORD = $(my_string "$REPLICATION_SECRET"), SOURCE_AUTO_POSITION = 1, SOURCE_SSL = 1, SOURCE_CONNECT_RETRY = 5, SOURCE_RETRY_COUNT = 3, SOURCE_CONNECTION_AUTO_FAILOVER = 1 FOR CHANNEL 'group';
SELECT asynchronous_connection_failover_add_managed('group', 'GroupReplication', $(my_string "$GROUP_NAME"), $(my_string "$PRIMARY"), 25060, '', 80, 60);
START REPLICA FOR CHANNEL 'group'
SQL
    wait_until 1800 2 channel_running || fail_task 'the server was not ready within 30m0s'
    : | write_file "$ROOT/state/replica" 600 false || true
}

require_saved
replication_password
if saved_is '.readOnly'; then
    follow_group
    exit 0
fi
require_primary_keys
if saved_is '.primary == null or .primary == .ip'; then
    phase 'bootstrapping'
    bootstrap_group "$REPLICATION_SECRET"
else
    phase 'joining'
    retry_or_fail 1800 30m0s join_attempt
fi
phase 'recovering'
online_and_joined "$REPLICATION_SECRET"
