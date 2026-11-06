@include('ssh.engines.mongodb.helpers')
mongo_run 60 <<'JS'
@include('ssh.engines.mongodb.cluster.voting')
const status = rs.status();
const primary = status.members.some((member) => member.stateStr === 'PRIMARY');
print(`online=${primary ? healthyVoterCount(status, rs.conf()) : 0}`);
print(`total=${status.votingMembersCount}`);
JS
