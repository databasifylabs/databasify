@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')

run_ignoring_restart_only() {
    my_try '' <<< "$1"
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 1238 '* ]]; then
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
}

@foreach ($sets as $statement)
run_ignoring_restart_only {!! $sh->arg($statement) !!}
@endforeach

applied=$(cat "$ROOT/state/applied.json" 2> /dev/null || echo '[]')
while IFS= read -r key; do
    [ -z "$key" ] && continue
    run_ignoring_restart_only "SET GLOBAL $key = DEFAULT"
@if ($engine->id === 'mysql')
    my '' > /dev/null <<< "RESET PERSIST IF EXISTS $key"
@endif
done < <(jq -r --argjson keep {!! $sh->arg(json_encode([...$keys, ...$static], JSON_THROW_ON_ERROR)) !!} '(. // [])[] | select(. as $key | $keep | index($key) | not)' <<< "$applied" 2> /dev/null)

printf '%s' {!! $sh->arg(json_encode($keys, JSON_THROW_ON_ERROR)) !!} | write_file "$ROOT/state/applied.json" 600 false || true
echo "Applied the settings."
