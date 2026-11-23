@include('engines._runtime')
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
ERRORS=$(mktemp)
@include('engines.postgres.extensions.packages._repository')
installed=$(dpkg-query -W -f='${Version}' {!! $sh->arg($fetch->package ?? '') !!} 2> /dev/null || true)
candidate=$(apt-cache policy {!! $sh->arg($fetch->package ?? '') !!} | sed -nE 's/^ *Candidate: *//p')
[ "$candidate" = '(none)' ] && candidate=''
rm -f "$ERRORS"
result "$(jq -cn --arg package {!! $sh->arg($fetch->package ?? '') !!} --arg installed "$installed" --arg candidate "$candidate" '{package: $package, installed: (if $installed == "" then null else $installed end), candidate: (if $candidate == "" then null else $candidate end)}')"
