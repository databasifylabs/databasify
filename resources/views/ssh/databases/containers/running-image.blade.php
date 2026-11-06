@include('ssh.databases.containers.helpers')
echo "image=$(running_image {{ $container }})"
