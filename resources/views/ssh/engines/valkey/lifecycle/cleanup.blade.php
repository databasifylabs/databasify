systemctl disable --now disable-thp.service > /dev/null 2>&1 || true
rm -f /etc/systemd/system/disable-thp.service /etc/sysctl.d/99-{{ $flavor }}.conf
thp_originals=/etc/databasify/thp-originals
for setting in enabled defrag; do
    original=$(sed -n "s/^$setting=//p" "$thp_originals" 2> /dev/null || true)
    if [ -w "/sys/kernel/mm/transparent_hugepage/$setting" ]; then
        echo "${original:-madvise}" > "/sys/kernel/mm/transparent_hugepage/$setting"
    fi
done
rm -f "$thp_originals"
@include('ssh.databases.host-tuning.reset')
