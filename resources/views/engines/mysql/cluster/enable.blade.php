@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
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
    MY_TIMEOUT=21600 my_try '' <<SQL
CLONE INSTANCE FROM 'databasify_repl'@$(my_string "$PRIMARY"):25060 IDENTIFIED BY $(my_string "$REPLICATION_SECRET") REQUIRE SSL
SQL
    if [[ $MY_ERROR_TEXT == 'Error 3707 '* ]]; then
        unit_do restart "$SERVER_UNIT"
    elif [ -n "$MY_ERROR" ] && [ "$MY_ERROR" != unavailable ]; then
        ATTEMPT_ERROR=$MY_ERROR_TEXT
        return 1
    fi
    wait_until 21600 2 clone_finished || {
        ATTEMPT_ERROR='the server was not ready within 6h0m0s'
        return 1
    }
    if [ "$CLONE_STATE" = Failed ]; then
        ATTEMPT_ERROR=$CLONE_ERROR
        return 1
    fi
}

clone_finished() {
    serving || return 1
    my_try '' <<< 'SELECT STATE, ERROR_MESSAGE FROM performance_schema.clone_status'
    [ -z "$MY_ERROR" ] || return 1
    IFS=$'\t' read -r CLONE_STATE CLONE_ERROR <<< "$MY_OUTPUT"
    [ "$CLONE_STATE" = Completed ] || [ "$CLONE_STATE" = Failed ]
}

group_plugin_loaded() {
    my_try '' <<< "SELECT PLUGIN_STATUS FROM information_schema.plugins WHERE PLUGIN_NAME = 'group_replication'"
    [ "$MY_OUTPUT" = ACTIVE ]
}

follow_group() {
    PRIMARY={!! $sh->arg((string) $saved->primary) !!}
    if [ -z "$PRIMARY" ]; then
        fail_task 'a read-only node needs the primary to follow'
    fi
    if [ -z "$(gtid_executed)" ]; then
        echo 'Cloning…'
        retry_or_fail 1800 30m0s clone_attempt
    fi
    echo 'Following…'
    my '' > /dev/null <<SQL
CHANGE REPLICATION SOURCE TO SOURCE_HOST = $(my_string "$PRIMARY"), SOURCE_PORT = 25060, SOURCE_USER = 'databasify_repl', SOURCE_PASSWORD = $(my_string "$REPLICATION_SECRET"), SOURCE_AUTO_POSITION = 1, SOURCE_SSL = 1, SOURCE_CONNECT_RETRY = 5, SOURCE_RETRY_COUNT = 3, SOURCE_CONNECTION_AUTO_FAILOVER = 1 FOR CHANNEL 'group';
SELECT asynchronous_connection_failover_add_managed('group', 'GroupReplication', {!! $sql->string($groupName) !!}, $(my_string "$PRIMARY"), 25060, '', 80, 60);
START REPLICA FOR CHANNEL 'group'
SQL
    wait_until 1800 2 channel_running || fail_task 'the server was not ready within 30m0s'
    : | write_file "$ROOT/state/replica" 600 false || true
}

[ -n "$REPLICATION_SECRET" ] || databasify_fail invalid_request 'MySQL clusters need the replication secret'
@if ($saved->readOnly)
follow_group
echo 'Following the primary as a read-only node.'
exit 0
@endif
require_primary_keys
if ! group_plugin_loaded; then
    echo 'Restarting into the new config…'
    unit_do restart "$SERVER_UNIT"
    wait_ready 'MySQL with Group Replication'
fi
@if (in_array($saved->primary, [null, $saved->ip], true))
echo 'Bootstrapping…'
bootstrap_group "$REPLICATION_SECRET"
JOINED='Started the group as its primary.'
@else
echo 'Joining…'
retry_or_fail 1800 30m0s join_attempt
JOINED='Joined the group as a standby.'
@endif
echo 'Recovering…'
online_and_joined "$REPLICATION_SECRET"
echo "$JOINED"
