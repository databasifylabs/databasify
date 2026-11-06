systemctl enable docker.service containerd.service > /dev/null 2>&1
if [ "$docker_daemon_changed" = 1 ] || ! systemctl is-active --quiet docker.service; then
    systemctl restart docker.service
fi
if [ "$(docker info --format '@{{.LiveRestoreEnabled}}')" != true ]; then
    echo "Docker started without live restore."
    exit 1
fi
echo "installed Docker $(docker version --format '@{{.Server.Version}}') with live restore"
