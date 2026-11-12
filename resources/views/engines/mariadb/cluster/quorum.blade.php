@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mariadb._state')
require_saved
total=$(jq '[.members[] | select(.voting)] | length' "$ROOT/state/node.json")
online=0
if read_state && [ "$STATE_CLUSTER" = Primary ]; then
    my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_CLUSTER_SIZE'"
    if [[ $MY_OUTPUT =~ ^[0-9]+$ ]]; then
        online=$MY_OUTPUT
    fi
    if [ "$online" -gt "$total" ]; then
        total=$online
    fi
fi
result "$(jq -cn --argjson total "$total" --argjson online "$online" '{total: $total, online: $online}')"
