. /etc/os-release
printf '{"os":"%s","id":"%s","version_id":"%s","selinux":"%s","cpus":%s,"memory_mb":%s,"disk_gb":%s,"subnets":[%s]}\n' \
    "${PRETTY_NAME:-}" "${ID:-}" "${VERSION_ID:-}" "$(getenforce 2> /dev/null || echo Disabled)" \
    "$(nproc)" "$(free -m | awk '/^Mem:/ { print $2 }')" \
    "$(df -BG --output=size / | tail -n 1 | tr -dc '0-9')" \
    "$(ip -4 -o addr show 2> /dev/null | awk '$2 != "lo" { printf "%s\"%s\"", (n++ ? "," : ""), $4 }')"
