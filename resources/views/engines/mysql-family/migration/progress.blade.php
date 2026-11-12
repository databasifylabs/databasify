@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
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
@if ($engine->id === 'mysql')
my_try '' <<'SQL'
@verbatim
SELECT c.SERVICE_STATE, a.SERVICE_STATE, GTID_SUBSET(c.RECEIVED_TRANSACTION_SET, @@gtid_executed), IFNULL(TIMESTAMPDIFF(SECOND, (SELECT MAX(LAST_APPLIED_TRANSACTION_ORIGINAL_COMMIT_TIMESTAMP) FROM performance_schema.replication_applier_status_by_worker WHERE CHANNEL_NAME = 'migration'), c.LAST_QUEUED_TRANSACTION_ORIGINAL_COMMIT_TIMESTAMP), 0) FROM performance_schema.replication_connection_status c JOIN performance_schema.replication_applier_status a USING (CHANNEL_NAME) WHERE c.CHANNEL_NAME = 'migration'
@endverbatim
SQL
if [ -z "$MY_ERROR" ] && [ -n "$MY_OUTPUT" ]; then
    IFS=$'\t' read -r receiving applying caught_up seconds <<< "$(head -n 1 <<< "$MY_OUTPUT")"
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
MY_HEADERS=true my_try '' <<< "SHOW SLAVE 'migration' STATUS"
if [ -z "$MY_ERROR" ] && [ -n "$MY_OUTPUT" ]; then
    lag=$(awk -F '\t' 'NR == 1 { for (i = 1; i <= NF; i++) column[$i] = i; next } NR == 2 { if ($column["Slave_IO_Running"] == "Yes" && $column["Slave_SQL_Running"] == "Yes" && $column["Seconds_Behind_Master"] ~ /^[0-9]+$/) print $column["Seconds_Behind_Master"]; else print "null" }' <<< "$MY_OUTPUT")
    lag=${lag:-null}
fi
@endif
result "$(jq -cn --arg copy "$copy" --arg log "$log" --argjson lag "$lag" '{copy: $copy, log: $log, lagSeconds: $lag}')"
