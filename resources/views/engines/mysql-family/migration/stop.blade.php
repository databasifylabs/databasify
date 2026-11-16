@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
databasify ctl stop {!! $sh->arg($engine->migrationUnit()) !!} > /dev/null 2>&1 || true
databasify ctl reset-failed {!! $sh->arg($engine->migrationUnit()) !!} > /dev/null 2>&1 || true

ignoring_missing() {
    my_try '' <<< "$1" 2> /dev/null
@if ($engine->id === 'mysql')
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 3074 '* ]]; then
@else
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 1617 '* ]]; then
@endif
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
}

@if ($engine->id === 'mysql')
ignoring_missing "STOP REPLICA FOR CHANNEL 'migration'"
ignoring_missing "RESET REPLICA ALL FOR CHANNEL 'migration'"
@else
ignoring_missing "STOP SLAVE 'migration'"
ignoring_missing "RESET SLAVE 'migration' ALL"
@endif
rm -f "$ROOT/state/{{ $engine->migrationUnit() }}.continuous"
echo "Stopped the migration."
