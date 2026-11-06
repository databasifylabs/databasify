@include('ssh.databases.helpers')
@include('ssh.databases.containers.pull-image')
image='{{ $image }}'
if docker image inspect "$image" > /dev/null 2>&1; then
    echo "$image is already on this server"
else
    pull_image "$image"
    echo "pulled $image"
fi
require_wrapper "$image"
