@include('ssh.databases.containers.helpers')
if ! container_running {{ $container }}; then
@if ($start)
    if ! docker start {{ $container }} > /dev/null; then
        echo "{{ $container }} could not start on this server"
        exit {{ $notRunning }}
    fi
    waited=0
    until docker exec {{ $container }} test -S /run/databasify.sock 2> /dev/null; do
        if [ "$waited" -ge 60 ]; then
            echo "{{ $container }} started, but its supervisor did not come up within 60s"
            exit {{ $notRunning }}
        fi
        sleep 1
        waited=$((waited + 1))
    done
    echo "started {{ $container }}"
@else
    echo "{{ $container }} is not running on this server"
    exit {{ $notRunning }}
@endif
fi
step_status=0
echo '{{ $script }}' | base64 -d | docker exec -i {{ $container }} databasify step {{ $step }} || step_status=$?
[ "$step_status" != {{ $notRunning }} ] || step_status=1
exit "$step_status"
