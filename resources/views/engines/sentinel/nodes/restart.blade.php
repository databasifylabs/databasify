@include('engines.sentinel._cli')
@include('engines.sentinel._node')
@if ($names !== [])
if [ -f "$CONF" ]; then
    kept=$(awk -v names={!! $sh->arg(implode(' ', $names)) !!} 'BEGIN { split(names, list, " ") } { for (i in list) if (index($0, list[i] " ") == 1) next; print }' "$CONF")
    printf '%s\n' "$kept" {!! collect($lines)->map($sh->arg(...))->implode(' ') !!} | write_file "$CONF" 600 true || true
fi
@endif
unit_do restart "$FLAVOR-server"
echo "Restarted $FLAVOR."
