@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')

run_ignoring_restart_only() {
    my_try '' <<< "$1"
    if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != 'Error 1238 '* ]]; then
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
}

while IFS= read -r statement; do
    [ -n "$statement" ] && run_ignoring_restart_only "$statement"
done < <(jq -r '.[]' <<< "$SETS")

applied=$(cat "$ROOT/state/applied.json" 2> /dev/null || echo '[]')
while IFS= read -r key; do
    [ -z "$key" ] && continue
    run_ignoring_restart_only "SET GLOBAL $key = DEFAULT"
    if [ -n "$FORGET" ]; then
        my '' > /dev/null <<< "$FORGET $key"
    fi
done < <(jq -r --argjson keys "$KEYS" --argjson static "$STATIC" '(. // [])[] | select(. as $key | ($keys | index($key) | not) and ($static | index($key) | not))' <<< "$applied" 2> /dev/null)

printf '%s' "$KEYS" | write_file "$ROOT/state/applied.json" 600 false || true
result '{}'
