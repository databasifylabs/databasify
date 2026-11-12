@include('engines.sentinel._cli')
unit="$FLAVOR-migration"
property() {
    databasify ctl show -p "$1" --value "$unit" 2> /dev/null || true
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
log=$(tail -n 3 "$ROOT/logs/$unit.log" 2> /dev/null | tr '\n' ' ' | sed -e 's/ $//' -e $'s/\x1b\\[[0-9;]*m//g' || true)
lag=null
if [[ $log == *'syncing aof, diff=[0]'* ]]; then
    lag=0
    if [ "$copy" = running ]; then
        copy=succeeded
    fi
fi
result "$(jq -cn --arg copy "$copy" --arg log "$log" --argjson lag "$lag" '{copy: $copy, log: $log, lagSeconds: $lag}')"
