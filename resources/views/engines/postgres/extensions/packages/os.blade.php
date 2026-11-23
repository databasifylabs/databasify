@include('engines._runtime')
. /etc/os-release
result "$(jq -cn --arg name "${NAME% GNU/Linux} ${VERSION_ID} (${VERSION_CODENAME})" '{name: $name}')"
