@if ($readOnly)
echo "{{ $node }} follows the members without joining etcd"
@else
@include('ssh.engines.postgres.helpers')
peer=http://{{ $node }}:{{ $etcdPeerPort }}
if etcd_ctl member list | grep -q "$peer"; then
    echo "{{ $node }} is already an etcd member"
else
    etcd_ctl member add {{ $node }} --peer-urls="$peer" --learner > /dev/null
    echo "{{ $node }} added to etcd as a learner, so the members keep their quorum while it starts"
fi
@endif
