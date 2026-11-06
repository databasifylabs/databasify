install -d -m 755 /etc/docker
docker_daemon=/etc/docker/daemon.json
docker_settings=$(cat <<'JSON'
{
    "live-restore": true,
    "iptables": false,
    "ip6tables": false,
    "bridge": "none",
    "log-driver": "json-file",
    "log-opts": {
        "max-size": "50m",
        "max-file": "5"
    }
}
JSON
)
docker_current=$(cat "$docker_daemon" 2> /dev/null || true)
[ -n "$docker_current" ] || docker_current='{}'
if ! jq -e 'type == "object"' <<< "$docker_current" > /dev/null 2>&1; then
    echo "$docker_daemon is not a JSON object, fix or remove it and provision again."
    exit 1
fi
docker_merged=$(jq -S -n --argjson current "$docker_current" --argjson wanted "$docker_settings" '$current + $wanted')
docker_daemon_changed=0
if [ "$docker_merged" != "$(jq -S . <<< "$docker_current")" ]; then
    printf '%s\n' "$docker_merged" > "$docker_daemon"
    docker_daemon_changed=1
fi
