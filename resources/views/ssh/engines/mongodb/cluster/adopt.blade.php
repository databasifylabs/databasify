@include('ssh.engines.mongodb.helpers')
mongo_run <<'JS'
@include('ssh.engines.mongodb.cluster.peers')
@include('ssh.engines.mongodb.cluster.horizons')
const host = '{{ $instance }}';
if (rs.conf().members.some((member) => member.host === host)) {
    print(`${host} is already a member`);
} else {
    const voters = rs.conf().members.filter((member) => member.votes > 0).length;
    const weight = {{ $readOnly ? 'true' : 'false' }} || voters >= {{ $maximumVoters }} ? 0 : 1;
    rs.add({ host, votes: weight, priority: weight, ...horizonsOf({!! json_encode($alias ?? null) !!}) });
    print(`added ${host} to the replica set with ${weight} vote`);
}
JS
