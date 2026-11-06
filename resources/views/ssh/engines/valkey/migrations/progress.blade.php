state() { databasify ctl show -p "$1" --value {{ $flavor }}-migration; }
if [ "$(state LoadState)" = 'not-found' ]; then
    echo "unit=missing"
    exit 0
fi
[ "$(state ActiveState)" = 'active' ] && [ "$(state SubState)" = 'running' ] && echo "running=true" || echo "running=false"
printf 'exit_code=%s\n' "$(state ExecMainStatus)"
log=$(databasify ctl logs --unit {{ $flavor }}-migration --lines 3 --output cat 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | tr '\n' ' ')
grep -q 'syncing aof, diff=\[0\]' <<< "$log" && echo "consistent=true" || echo "consistent=false"
printf 'log=%s\n' "$log"
