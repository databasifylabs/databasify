@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
crashed() { [ "$(databasify ctl show -p NRestarts --value mongod)" -gt 3 ] || databasify ctl is-failed --quiet mongod; }
ready() {
    if crashed; then
        databasify ctl logs --unit mongod --lines 20
        echo "mongod stopped while starting"
        exit 1
    fi
@if ($expectPrimary)
    writable_primary
@else
    serving
@endif
}
client_up() { ss -ltn | grep -q ':{{ $clientPort }} '; }
wait_for {{ $deadline }} ready
wait_for 300 client_up
own_role | sed 's/^/role:/'
