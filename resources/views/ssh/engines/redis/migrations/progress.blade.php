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
last_run=$(databasify ctl logs --unit {{ $flavor }}-migration --lines 500 --output cat 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | awk '/databasify: started/ { run = "" } { run = run $0 "\n" } END { printf "%s", run }')
module_type=$({ grep -oE 'unsupported module type: [^ ]+' <<< "$last_run" || true; } | tail -n 1 | sed 's/.*: //')
rdb_type=$({ grep -oE 'unknown rdb value type byte\. key=\[[^]]*\], type=\[[0-9]+\]' <<< "$last_run" || true; } | tail -n 1 | sed -E 's/.*key=\[(.*)\], type=\[([0-9]+)\]/key \1 (RDB type \2, newer than redis-shake reads)/')
printf 'unreadable=%s\n' "${module_type:+$module_type keys, a Redis module type}${rdb_type}"
