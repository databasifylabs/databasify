@include('ssh.databases.helpers')
@include('ssh.databases.containers.helpers')
@include('ssh.databases.containers.pull-image')
@if ($fresh)
if [ -n "$(ls -A "$dir/data" 2>/dev/null)" ] && [ "$(cat "$dir/.database" 2>/dev/null)" != '{{ $databaseKey }}' ]; then
    echo "$dir/data holds data from another database ($(cat "$dir/.database" 2>/dev/null || echo unknown))."
    echo "Erase it before reusing this server: delete that database with erase, or remove $dir."
    exit 1
fi
@endif
if ! command -v docker > /dev/null 2>&1; then
    echo "Docker is not installed on this server, provision it again first."
    exit 1
fi
image='{{ $image }}'
if ! docker image inspect "$image" > /dev/null 2>&1; then
    pull_image "$image"
    echo "pulled $image"
fi
require_wrapper "$image"
engine_ids=$(image_ids "$image" {{ $engineUser }})
use_engine_ids "$engine_ids"
@include('ssh.databases.containers.engine-user')
running_image=$(running_image {{ $container }})
if [ -n "$running_image" ] && [ "$running_image" != "$image" ]; then
    docker stop {{ $container }} > /dev/null
    docker rm -v {{ $container }} > /dev/null
    echo "removed {{ $container }} running $running_image"
fi
install -d -m 755 -o "$engine_uid" -g "$engine_gid" "$dir"
install -d -m 750 -o "$engine_uid" -g "$engine_gid" "$dir/tls" "$dir/conf"
@if ($fresh)
rm -f "$dir"/tls/*
@endif
echo '{{ $databaseKey }}' > "$dir/.database"
@foreach ($files as $file)
@include('ssh.databases.files.put', ['owner' => $file->hostOwner()])
@endforeach
@include('ssh.servers.os.swap')
@include($hostTuning)
@include('ssh.databases.recovery.archive-off')
if ! docker container inspect {{ $container }} > /dev/null 2>&1; then
    {!! $run !!} > /dev/null
    echo "created {{ $container }} from $image"
elif ! container_running {{ $container }}; then
    docker start {{ $container }} > /dev/null
    echo "started {{ $container }}"
else
    ctl daemon-reload
fi
docker exec -i {{ $container }} databasify step prepare <<'PREPARE'
set -euo pipefail
@include($prepare)
PREPARE
if [ -n "$running_image" ] && [ "$running_image" != "$image" ] && container_running {{ $container }}; then
    current_image_id=$(docker image inspect -f '@{{.Id}}' "$image")
    docker image ls --no-trunc --format '@{{.Repository}} @{{.ID}}' | awk -v repository='{{ $repository }}' -v current="$current_image_id" '$1 == repository && $2 != current { print $2 }' | sort -u | xargs -r docker rmi > /dev/null 2>&1 || true
    echo "pruned the {{ $repository }} images this node no longer runs"
fi
echo "deployed configuration to $dir"
