@include('ssh.databases.helpers')
@include('ssh.databases.containers.helpers')
use_engine_ids "$(stat -c %u:%g "$dir")"
@foreach ($files as $file)
@include('ssh.databases.files.put', ['owner' => $file->hostOwner()])
@endforeach
@include('ssh.databases.recovery.archive-off')
if container_running {{ $container }}; then
    ctl daemon-reload
fi
echo "archive settings written"
