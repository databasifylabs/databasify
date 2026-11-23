@include('engines._runtime')
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
files=(@foreach ($fileNames as $fileName){!! $sh->arg("{$directory}/{$fileName}") !!} @endforeach)
errors=$(mktemp)
apt-get install -y -qq --no-install-recommends{{ ($allowDowngrades ?? false) ? ' --allow-downgrades' : '' }} -o Dir::Etc::SourceList=/dev/null -o Dir::Etc::SourceParts=/dev/null "${files[@]}" > /dev/null 2> "$errors" || fail_task "Could not install the packages: $(tail -n 3 "$errors")"
for file in "${files[@]}"; do
    printf '%s %s is installed.\n' "$(dpkg-deb -f "$file" Package)" "$(dpkg-deb -f "$file" Version)"
done
