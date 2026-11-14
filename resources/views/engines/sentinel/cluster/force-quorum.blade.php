@include('engines.sentinel._cli')
@include('engines.sentinel._node')
databasify ctl reset-failed "$FLAVOR-server" "$FLAVOR-sentinel" > /dev/null 2>&1 || true
databasify ctl start "$FLAVOR-sentinel" > /dev/null 2>&1 || true
newest_primary
if [ -n "$NEWEST_PRIMARY" ] && [ "$NEWEST_EPOCH" -ge 0 ]; then
    if [ "$NEWEST_PRIMARY" = "$SELF" ]; then
        require_replicas 0
    elif ! unit_active "$FLAVOR-server"; then
        set_replica_of "$NEWEST_PRIMARY"
        unit_do start "$FLAVOR-server"
    fi
    echo "Rejoined the newest primary."
    exit 0
fi
highest=0
while IFS= read -r epoch; do
    [[ $epoch =~ ^[0-9]+$ ]] && [ "$epoch" -gt "$highest" ] && highest=$epoch
done < <(sed -n -e 's/^sentinel current-epoch \([0-9]*\)$/\1/p' -e 's/^sentinel config-epoch main \([0-9]*\)$/\1/p' "$ROOT/sentinel/sentinel.conf" 2> /dev/null)
while IFS= read -r host; do
    SENTINEL_HOST=$host cli_try sentinel_cli SENTINEL MASTER main
    [ -n "$CLI_ERROR" ] && continue
    epoch=$(pair_value "$CLI_OUTPUT" config-epoch)
    [[ $epoch =~ ^[0-9]+$ ]] && [ "$epoch" -gt "$highest" ] && highest=$epoch
done < <(sentinel_hosts | awk '!seen[$0]++')
epoch=$(( highest + 1 ))
unit_do stop "$FLAVOR-sentinel"
rewritten=$(grep -v -E '^sentinel (config-epoch|leader-epoch) main [0-9]+$|^sentinel current-epoch [0-9]+$|^sentinel known-replica ' "$ROOT/sentinel/sentinel.conf" | sed "s/^sentinel monitor main [^ ]* /sentinel monitor main $SELF /"; printf x)
rewritten=${rewritten%x}
printf '%ssentinel config-epoch main %d\nsentinel leader-epoch main %d\nsentinel current-epoch %d\n' "$rewritten" "$epoch" "$epoch" "$epoch" | write_file "$ROOT/sentinel/sentinel.conf" 600 true || true
set_replica_of ''
if holding; then
    write_conf "$(grep -vx 'replica-priority 0' "$CONF" || true)"$'\n'
fi
rm -f "$ROOT/conf/start-refused"
if unit_active "$FLAVOR-server"; then
    cli_try local_cli REPLICAOF NO ONE
    cli_try local_cli CONFIG SET replica-priority 100
else
    unit_do start "$FLAVOR-server"
fi
is_master() { [ "$(current_role)" = master ]; }
wait_until 600 2 is_master || databasify_fail internal "$FLAVOR did not become ready within 10m0s"
require_replicas 0
unit_do start "$FLAVOR-sentinel"
wait_until 60 2 sentinel_answers || databasify_fail internal "$FLAVOR did not become ready within 1m0s"
echo "Forced a new quorum from the healthy members."
