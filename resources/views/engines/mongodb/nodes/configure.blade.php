@include('engines.mongodb._mongo')
@include('engines._write')
for directory in conf data state; do
    engine_directory "$directory"
done
if [ -n "$TUNED_JSON" ] && [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' "$TUNED_JSON" | write_file "$ROOT/state/tuned.json" 600 false || true
fi
changed=false
while IFS=$'\t' read -r path mode content; do
    [ -z "$path" ] && continue
    owned=false
    if [ "$path" = "$ROOT/conf/mongod.conf" ] || [ "$path" = "$ROOT/conf/keyfile" ]; then
        owned=true
    fi
    if printf '%s' "$content" | base64 -d | write_file "$path" "$mode" "$owned"; then
        changed=true
    fi
done <<< "$FILES"
if printf '%s' "$MANIFEST" | base64 -d | write_file "$ROOT/conf/processes.json" 600 false; then
    changed=true
fi
printf '%s' "$REQUEST_JSON" | write_file "$ROOT/state/node.json" 600 false || true
printf '{}' | databasify service reload > /dev/null
if [ "$START_AGENT" = true ] && unit_active mongod; then
    unit_do start pbm-agent
fi
result "{\"changed\":$changed}"
