@include('engines._runtime')
set -euo pipefail
names='[]'
for file in {!! $sh->arg("{$directory}/incoming/{$run}") !!}/*.deb; do
    [ -e "$file" ] || continue
    names=$(jq -c --arg name "$(dpkg-deb -f "$file" Package)" '. + [$name]' <<< "$names")
done
result "$(jq -cn --argjson names "$names" '{names: $names}')"
