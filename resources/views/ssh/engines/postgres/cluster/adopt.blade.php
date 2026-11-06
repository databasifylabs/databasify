@unless ($readOnly)
@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
peer=http://{{ $node }}:{{ $etcdPeerPort }}
learner=$({ etcd_ctl member list || true; } | awk -F ', ' -v peer="$peer" '$4 == peer && $6 == "true" { print $1 }')
if [ -n "$learner" ]; then
    promoted() { etcd_ctl member promote "$learner" > /dev/null 2>&1; }
    wait_for 300 promoted
    echo "{{ $node }} caught up and now votes in etcd"
fi
@endunless
