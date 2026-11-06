@include('ssh.databases.helpers')
@include('ssh.databases.containers.helpers')
use_engine_ids "$(stat -c %u:%g "$dir")"
@foreach ($files as $file)
@include('ssh.databases.files.put', ['owner' => $file->hostOwner()])
@endforeach
if ! container_running {{ $container }}; then
    echo "{{ $container }} is not running, it reads the new certificates when it starts"
    exit 0
fi
docker exec -i {{ $container }} databasify step reload-tls <<'RELOAD'
set -euo pipefail
dir={{ $directory }}
@include($reload)
RELOAD
echo "reloaded certificates"
