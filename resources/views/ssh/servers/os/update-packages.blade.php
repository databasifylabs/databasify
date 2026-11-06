if command -v cloud-init > /dev/null 2>&1; then
    timeout {{ $aptTimeout }} cloud-init status --wait > /dev/null 2>&1 || true
fi
@include('ssh.servers.os.packages')
newer_kernel_installed() {
    local newest
    newest=$(ls /lib/modules 2> /dev/null | sort -V | tail -n 1 || true)
    [ -n "$newest" ] && [ "$newest" != "$(uname -r)" ]
}
if [ "$(os_family)" = rhel ]; then
    dnf upgrade -y -q --refresh
    dnf autoremove -y -q
    dnf needs-restarting -r > /dev/null 2>&1 && echo "packages are up to date" || echo "a reboot is required to finish the update"
else
    apt-get update -q
    apt-get upgrade -yq --with-new-pkgs
    apt-get autoremove -yq
    { [ -f /var/run/reboot-required ] || newer_kernel_installed; } && echo "a reboot is required to finish the update" || echo "packages are up to date"
fi
