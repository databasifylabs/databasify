@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
MIGRATION_UNIT={!! $sh->arg($engine->migrationUnit()) !!}
property() {
    databasify ctl show -p "$1" --value "$MIGRATION_UNIT" 2> /dev/null || true
}

if [ "$(property LoadState)" = not-found ]; then
    result '{"copy":"lost","log":"","lagSeconds":null}'
    exit 0
fi
copy=failed
if [ "$(property ActiveState)" = active ] && [ "$(property SubState)" = running ]; then
    copy=running
elif [ "$(property ExecMainStatus)" = 0 ]; then
    copy=succeeded
fi
log=$(tail -n 3 "$ROOT/logs/$MIGRATION_UNIT.log" 2> /dev/null | tr '\n' ' ' | sed 's/ $//' || true)
lag=null
channel=present
@if ($engine->id === 'mysql')
@include('engines.mysql._channel')
my_try '' 2> /dev/null <<SQL
@verbatim
SELECT c.SERVICE_STATE, a.SERVICE_STATE, GTID_SUBSET(c.RECEIVED_TRANSACTION_SET, @@gtid_executed), IFNULL(TIMESTAMPDIFF(SECOND, (SELECT MAX(LAST_APPLIED_TRANSACTION_ORIGINAL_COMMIT_TIMESTAMP) FROM performance_schema.replication_applier_status_by_worker WHERE CHANNEL_NAME = 'migration'), c.LAST_QUEUED_TRANSACTION_ORIGINAL_COMMIT_TIMESTAMP), 0), $(last_channel_error_sql migration) FROM performance_schema.replication_connection_status c JOIN performance_schema.replication_applier_status a USING (CHANNEL_NAME) WHERE c.CHANNEL_NAME = 'migration'
@endverbatim
SQL
if [ -n "$MY_ERROR" ]; then
    channel=unreadable
elif [ -z "$MY_OUTPUT" ]; then
    channel=missing
else
    IFS=$'\t' read -r receiving applying caught_up seconds channel_error <<< "$(head -n 1 <<< "$MY_OUTPUT")"
    if [ "$copy" = succeeded ] && { [ "$receiving" = OFF ] || [ "$applying" = OFF ]; }; then
        copy=failed
        log="the migration channel stopped: ${channel_error:-no error was recorded}"
    fi
    if [ "$receiving" = ON ] && [ "$applying" = ON ]; then
        if [ "$caught_up" = 1 ]; then
            lag=0
        elif [[ $seconds =~ ^-?[0-9]+$ ]]; then
            lag=$(( seconds < 0 ? 0 : seconds ))
        else
            lag=0
        fi
    fi
fi
@else
MY_HEADERS=true my_try '' <<< "SHOW SLAVE 'migration' STATUS" 2> /dev/null
if [[ $MY_ERROR_TEXT == 'Error 1617 '* ]] || { [ -z "$MY_ERROR" ] && [ -z "$MY_OUTPUT" ]; }; then
    channel=missing
elif [ -n "$MY_ERROR" ]; then
    channel=unreadable
else
    channel_status=$(awk -F '\t' 'NR == 1 { for (i = 1; i <= NF; i++) column[$i] = i; next } NR == 2 { if ($column["Slave_IO_Running"] == "Yes" && $column["Slave_SQL_Running"] == "Yes" && $column["Seconds_Behind_Master"] ~ /^[0-9]+$/) lag = $column["Seconds_Behind_Master"]; else lag = "null"; if ($column["Slave_IO_Running"] == "No" || $column["Slave_SQL_Running"] == "No") error = $column["Last_SQL_Errno"] != 0 ? "Error " $column["Last_SQL_Errno"] ": " $column["Last_SQL_Error"] : $column["Last_IO_Errno"] != 0 ? "Error " $column["Last_IO_Errno"] ": " $column["Last_IO_Error"] : "no error was recorded" } END { printf "%s\037%s", lag, error }' <<< "$MY_OUTPUT")
    lag=${channel_status%%$'\037'*}
    lag=${lag:-null}
    channel_error=${channel_status#*$'\037'}
    if [ "$copy" = succeeded ] && [ -n "$channel_error" ]; then
        copy=failed
        log="the migration channel stopped: $channel_error"
    fi
fi
@endif
if [ "$copy" = succeeded ] && [ -e "$ROOT/state/$MIGRATION_UNIT.continuous" ]; then
    case "$channel" in
        missing)
            copy=failed
            log='the migration channel does not exist on this server'
            ;;
        unreadable)
            copy=lost
            log="could not read the migration channel: $MY_ERROR_TEXT"
            ;;
    esac
fi
result "$(jq -cn --arg copy "$copy" --arg log "$log" --argjson lag "$lag" '{copy: $copy, log: $log, lagSeconds: $lag}')"
