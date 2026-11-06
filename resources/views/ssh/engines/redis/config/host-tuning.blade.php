tuning=/etc/sysctl.d/99-{{ $flavor }}.conf
@include('ssh.databases.host-tuning.originals')
somaxconn=$(sed -n 's/^net.core.somaxconn = //p' "$originals" 2> /dev/null || true)
somaxconn=${somaxconn:-$(sysctl -n net.core.somaxconn)}
wanted=$(cat <<CONF
vm.overcommit_memory = 1
net.core.somaxconn = $(( somaxconn > 1024 ? somaxconn : 1024 ))
CONF
)
@include('ssh.databases.host-tuning.apply')
thp_originals=/etc/databasify/thp-originals
if [ ! -f /etc/systemd/system/disable-thp.service ] && [ ! -f "$thp_originals" ]; then
    install -d -m 755 /etc/databasify
    for setting in enabled defrag; do
        echo "$setting=$(sed -n 's/.*\[\(.*\)\].*/\1/p' "/sys/kernel/mm/transparent_hugepage/$setting" 2> /dev/null || true)"
    done > "$thp_originals"
fi
cat > /etc/systemd/system/disable-thp.service <<'UNIT'
[Unit]
Description=Disable transparent huge pages for {{ ucfirst($flavor) }}
After=tuned.service
Before=docker.service

[Service]
Type=oneshot
ExecStart=/bin/sh -c 'echo never > /sys/kernel/mm/transparent_hugepage/enabled && echo never > /sys/kernel/mm/transparent_hugepage/defrag'

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now disable-thp.service

echo "overcommit_memory=$(cat /proc/sys/vm/overcommit_memory) somaxconn=$(cat /proc/sys/net/core/somaxconn) thp=$(cat /sys/kernel/mm/transparent_hugepage/enabled)"
