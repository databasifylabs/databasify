@include('engines.sentinel._cli')
values='{}'
for name in $NAMES; do
    value=$(config_value "$name")
    values=$(jq -c --arg name "$name" --arg value "$value" '. + {($name): $value}' <<< "$values")
done
result "$(jq -cn --argjson values "$values" '{values: $values}')"
