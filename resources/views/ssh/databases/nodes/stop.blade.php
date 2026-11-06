@include('ssh.databases.helpers')
@include('ssh.databases.containers.helpers')
if container_running {{ $container }}; then
    docker stop {{ $container }} > /dev/null
    echo "stopped {{ $container }}"
fi
@if ($erase)
docker rm -v -f {{ $container }} > /dev/null 2>&1 || true
docker image ls --format '@{{.Repository}} @{{.ID}}' | awk -v repository='{{ $repository }}' '$1 == repository { print $2 }' | sort -u | xargs -r docker rmi -f > /dev/null 2>&1 || true
@include($cleanup)
rm -rf "$dir"
echo "stopped and erased $dir"
engine_data_left() {
    for engine_dir in {{ $engineDirectories }}; do
        [ ! -e "$engine_dir" ] || return 0
    done
    return 1
}
if [ "$(getent passwd {{ $engineUid }} | cut -d: -f1 || true)" = {{ $engineHostUser }} ] && ! engine_data_left; then
    userdel {{ $engineHostUser }} > /dev/null 2>&1 || true
    ! getent group {{ $engineHostUser }} > /dev/null || groupdel {{ $engineHostUser }}
    echo "removed the {{ $engineHostUser }} user, no database data is left on this server"
fi
@else
echo "stopped; data in $dir is kept"
@endif
