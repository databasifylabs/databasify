systemctl daemon-reload
systemctl enable docker.socket docker.service containerd.service > /dev/null 2>&1
if [ "$docker_daemon_changed" = 1 ] || ! systemctl is-active --quiet docker.service; then
    systemctl reset-failed docker.socket docker.service > /dev/null 2>&1 || true
    systemctl restart docker.socket docker.service
fi
if [ "$(docker info --format '@{{.LiveRestoreEnabled}}')" != true ]; then
    echo "Docker started without live restore."
    exit 1
fi
echo "installed Docker $(docker version --format '@{{.Server.Version}}') with live restore"
