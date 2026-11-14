@include('engines.sentinel._cli')
@include('engines.sentinel._node')
for directory in conf data sentinel state; do
    engine_directory "$directory"
done
if [ -n "$TUNED_JSON" ] && [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' "$TUNED_JSON" | write_file "$ROOT/state/tuned.json" 600 true || true
fi
changed=false
printf '%s' "$PASSWORD" | write_file "$ROOT/conf/password" 600 true && changed=true
printf '%s' "$SERVER_CONF" | base64 -d | write_file "$CONF" 600 true && changed=true
if [ -n "$SENTINEL_CONF" ] && [ ! -e "$ROOT/sentinel/sentinel.conf" ]; then
    printf '%s' "$SENTINEL_CONF" | base64 -d | write_file "$ROOT/sentinel/sentinel.conf" 600 true && changed=true
fi
printf '%s' "$MANIFEST" | base64 -d | write_file "$ROOT/conf/processes.json" 600 true && changed=true
printf '%s' "$REQUEST_JSON" | write_file "$ROOT/state/node.json" 600 true || true
rm -f "$ROOT/conf/start-refused"
if [ "$GUARD_EMPTY_PRIMARY" = true ] && restart_loses_data "$PERSISTENT"; then
    for peer in $PEERS; do
        cli_try cli "$peer" 6379 DBSIZE
        if [ -z "$CLI_ERROR" ] && [[ $CLI_OUTPUT =~ ^[0-9]+$ ]] && [ "$CLI_OUTPUT" -gt 0 ]; then
            printf '%s' 'refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first' | write_file "$ROOT/conf/start-refused" 600 true || true
            break
        fi
    done
fi
daemon_reload
if [ "$changed" = true ]; then
    echo "Wrote the $FLAVOR configuration."
else
    echo "The $FLAVOR configuration was already current."
fi
