@include('engines.sentinel._cli')
values='{}'
for name in $NAMES; do
    value=$(local_cli CONFIG GET "$name")
    if [ "$(wc -l <<< "$value")" -lt 2 ]; then
        databasify_fail internal "$FLAVOR has no setting named $name"
    fi
    values=$(jq -c --arg name "$name" --arg value "$(sed -n 2p <<< "$value")" '. + {($name): $value}' <<< "$values")
done
result "$(jq -cn --argjson values "$values" '{values: $values}')"
