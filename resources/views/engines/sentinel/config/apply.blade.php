@include('engines.sentinel._cli')
@include('engines.sentinel._node')
originals=$(cat "$ROOT/state/originals.json" 2> /dev/null || echo '{}')
[ -n "$originals" ] || originals='{}'
while IFS= read -r name; do
    [ -z "$name" ] && continue
    if jq -e --arg name "$name" 'has($name)' <<< "$originals" > /dev/null; then
        continue
    fi
    value=$(config_value "$name")
    originals=$(jq -c --arg name "$name" --arg value "$value" '. + {($name): $value}' <<< "$originals")
done < <(jq -r --argjson keys "$CONFIG_KEYS" '.[] | $keys[.] // .' <<< "$SETTING_KEYS")
applied=$(cat "$ROOT/state/applied.json" 2> /dev/null || echo '[]')
desired=$(jq -c --argjson applied "${applied:-[]}" --argjson kept "$SETTING_KEYS" --argjson keys "$CONFIG_KEYS" --argjson originals "$originals" \
    'reduce ($applied[] | select(. as $key | $kept | index($key) | not)) as $key (.; .[($keys[$key] // $key)] = ($originals[($keys[$key] // $key)] // ""))' <<< "$DESIRED")
printf '%s' "$originals" | write_file "$ROOT/state/originals.json" 600 true || true
while IFS=$'\t' read -r name value; do
    [ -z "$name" ] && continue
    cli_try local_cli CONFIG SET "$name" "$value"
    if [ -n "$CLI_ERROR" ]; then
        databasify_fail internal "could not set $name: $CLI_ERROR_TEXT"
    elif [ "$CLI_OUTPUT" != OK ]; then
        databasify_fail internal "could not set $name: $CLI_OUTPUT"
    fi
done < <(jq -r 'to_entries | sort_by(.key)[] | [.key, .value] | @tsv' <<< "$desired")
printf '%s' "$SETTING_KEYS" | write_file "$ROOT/state/applied.json" 600 true || true
result '{}'
