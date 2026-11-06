@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
crashed() { [ "$(databasify ctl show -p NRestarts --value mariadb)" -gt 3 ] || databasify ctl is-failed --quiet mariadb; }
ready() {
    if crashed; then
        databasify ctl logs --unit mariadb --lines 20
        echo "mariadb stopped while starting"
        exit 1
    fi
@if ($expectPrimary)
    own_state | grep -q '^WRITER ONLINE'
@else
    own_state | grep -Eq '^(WRITER|MEMBER|READ_REPLICA) ONLINE'
@endif
}
client_up() { ss -ltn | grep -q ':{{ $clientPort }} '; }
wait_for {{ $deadline }} ready
wait_for 300 client_up
own_state
