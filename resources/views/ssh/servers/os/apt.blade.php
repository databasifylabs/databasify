apt_busy() {
    if command -v fuser > /dev/null 2>&1; then
        fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock > /dev/null 2>&1
    else
        pgrep -x 'apt|apt-get|dpkg' > /dev/null 2>&1
    fi
}
apt_wait() {
    local waited=0
    while apt_busy && [ "$waited" -lt {{ $aptTimeout }} ]; do
        [ "$waited" -eq 0 ] && echo "waiting for other package updates to finish"
        sleep 5
        waited=$((waited + 5))
    done
}
apt-get() { apt_wait; command apt-get -o DPkg::Lock::Timeout={{ $aptTimeout }} "$@"; }
