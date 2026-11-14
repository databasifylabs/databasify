@include('engines.sentinel._cli')
@include('engines.sentinel._node')
if [ ! -f "$ROOT/state/node.json" ]; then
    databasify_fail busy 'run node configure first'
fi
refused=$(cat "$ROOT/conf/start-refused" 2> /dev/null || true)
if [ -n "$refused" ]; then
    databasify_fail busy "$refused"
fi
if [ "$HOLDS" = true ] && [ -n "$(replica_of)" ] && restart_loses_data "$PERSISTENT"; then
    hold
fi
unit_do start "$FLAVOR-server"
echo "Started $FLAVOR."
