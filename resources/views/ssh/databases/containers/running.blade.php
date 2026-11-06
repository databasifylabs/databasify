@include('ssh.databases.containers.helpers')
container_running {{ $container }} || exit {{ $notRunning }}
