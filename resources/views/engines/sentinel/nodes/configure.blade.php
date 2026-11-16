@include('engines.sentinel._cli')
@include('engines.sentinel._node')
for directory in conf data sentinel state; do
    engine_directory "$directory"
done
@if ($tunedJson !== null)
if [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' {!! $sh->arg($tunedJson) !!} | write_file "$ROOT/state/tuned.json" 600 true || true
fi
@endif
changed=false
printf '%s' {!! $sh->arg($password) !!} | write_file "$ROOT/conf/password" 600 true && changed=true
printf '%s' {!! $sh->arg($serverConf) !!} | write_file "$CONF" 600 true && changed=true
@if ($sentinelConf !== null)
if [ ! -e "$ROOT/sentinel/sentinel.conf" ]; then
    printf '%s' {!! $sh->arg($sentinelConf) !!} | write_file "$ROOT/sentinel/sentinel.conf" 600 true && changed=true
fi
@endif
printf '%s' {!! $sh->arg($manifest) !!} | write_file "$ROOT/conf/processes.json" 600 true && changed=true
printf '%s' {!! $sh->arg($requestJson) !!} | write_file "$ROOT/state/node.json" 600 true || true
rm -f "$ROOT/conf/start-refused"
@if ($guardsEmptyPrimary)
if restart_loses_data {{ $persistent ? 'true' : 'false' }}; then
    for peer in {!! collect($peers)->map($sh->arg(...))->implode(' ') !!}; do
        cli_try cli "$peer" 6379 DBSIZE
        if [ -z "$CLI_ERROR" ] && [[ $CLI_OUTPUT =~ ^[0-9]+$ ]] && [ "$CLI_OUTPUT" -gt 0 ]; then
            printf '%s' 'refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first' | write_file "$ROOT/conf/start-refused" 600 true || true
            break
        fi
    done
fi
@endif
unit_do daemon-reload
if [ "$changed" = true ]; then
    echo "Wrote the $FLAVOR configuration."
else
    echo "The $FLAVOR configuration was already current."
fi
