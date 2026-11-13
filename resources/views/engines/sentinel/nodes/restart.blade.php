@include('engines.sentinel._cli')
@include('engines.sentinel._node')
if [ -f "$CONF" ] && [ "$DIRECTIVES" != '[]' ]; then
    text=$(cat "$CONF"; printf x)
    text=${text%x}
    while IFS=$'\t' read -r name line; do
        [ -z "$name" ] && continue
        text=$(printf '%s' "$text" | awk -v name="$name" 'index($0, name " ") != 1'; printf x)
        text=${text%x}
        text=${text%$'\n'}$'\n'
        if [ "$line" != null ]; then
            text+="$line"$'\n'
        fi
    done < <(jq -r '.[] | [.name, (.line // "null")] | @tsv' <<< "$DIRECTIVES")
    write_conf "$text"
fi
unit_do restart "$FLAVOR-server"
echo "Restarted $FLAVOR."
