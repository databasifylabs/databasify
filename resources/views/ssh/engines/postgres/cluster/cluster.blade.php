@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
databasify ctl daemon-reload
databasify ctl enable --now databasify-etcd > /dev/null
etcd_up() { etcd_ctl endpoint health > /dev/null 2>&1; }
wait_for 120 etcd_up
databasify ctl reset-failed databasify-patroni > /dev/null 2>&1 || true
databasify ctl enable --now databasify-patroni > /dev/null
leading() {
    if crash_looping databasify-patroni 3; then
        echo "patroni keeps failing to start"
        exit 1
    fi
    patroni_state | grep -q '^role:primary state:running'
}
wait_for 600 leading
databasify ctl restart databasify-pgbouncer
echo "patroni manages postgres on this node now"
