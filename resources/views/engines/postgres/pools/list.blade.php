@include('engines.postgres._psql')
saved=$(cat /srv/postgres/conf/pools.json 2> /dev/null || echo '[]')
if ! jq -e 'type == "array"' <<< "$saved" > /dev/null 2>&1; then
    saved='[]'
fi
result "$(jq -c '{pools: .}' <<< "$saved")"
