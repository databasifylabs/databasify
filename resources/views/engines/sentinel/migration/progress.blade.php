@include('engines.sentinel._cli')
property() {
    databasify ctl show -p "$1" --value "$FLAVOR-migration" 2> /dev/null || true
}
log=$(tail -n 3 "$ROOT/logs/$FLAVOR-migration.log" 2> /dev/null || true)
output=$(jq -cn --arg loadState "$(property LoadState)" --arg activeState "$(property ActiveState)" --arg subState "$(property SubState)" --arg exitStatus "$(property ExecMainStatus)" --arg log "$log" \
    '{loadState: $loadState, activeState: $activeState, subState: $subState, exitStatus: $exitStatus, log: $log}')
result "$output"
