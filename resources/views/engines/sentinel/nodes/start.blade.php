@include('engines.sentinel._cli')
@include('engines.sentinel._node')
refused=$(cat "$ROOT/conf/start-refused" 2> /dev/null || true)
if [ -n "$refused" ]; then
    databasify_fail busy "$refused"
fi
@if ($holds)
if [ -n "$(replica_of)" ] && restart_loses_data {{ $persistent ? 'true' : 'false' }}; then
    hold
fi
@endif
unit_do start "$FLAVOR-server"
echo "Started $FLAVOR."
