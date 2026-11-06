state() { databasify ctl show -p "$1" --value {{ $migrationUnit }}; }
if [ "$(state LoadState)" = 'not-found' ]; then
    echo "unit=missing"
    exit 0
fi
[ "$(state ActiveState)" = 'active' ] && [ "$(state SubState)" = 'running' ] && echo "running=true" || echo "running=false"
printf 'exit_code=%s\n' "$(state ExecMainStatus)"
repl=$({ curl -fsS --max-time 5 http://127.0.0.1:9100/repl 2> /dev/null || true; } | jq -c 'if type == "array" then .[0] else . end' 2> /dev/null || true)
full=$(curl -fsS --max-time 5 http://127.0.0.1:9101/progress 2> /dev/null || true)
full=${full:-'{}'}
copying() { jq -e '(.progress // "100.00%") != "100.00%"' <<< "$full" > /dev/null 2>&1; }
if [ -n "$repl" ] && jq -e '.lsn' <<< "$repl" > /dev/null 2>&1 && ! copying; then
    echo "phase=incremental"
    jq -r '"lag=\([(.lsn.unix // 0) - (.lsn_ack.unix // 0), 0] | max)"' <<< "$repl"
else
    echo "phase=full"
    jq -r '"copied=\(.progress // "starting") of \(.total_collection_number // 0) collections"' <<< "$full" 2> /dev/null || true
fi
printf 'log=%s\n' "$(tail -n 2 {{ $migrationStateDir }}/logs/collector.log 2> /dev/null | tr '\n' ' ')"
