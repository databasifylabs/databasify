@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
mongo_run 600 <<'JS'
@include('ssh.engines.mongodb.cluster.voting')
const hasPrimary = () => rs.status().members.some((member) => member.stateStr === 'PRIMARY');
const healthyHosts = () => rs.status().members.filter((member) => member.health === 1).map((member) => member.name);
const majorityIsHealthy = () => {
    const status = rs.status();
    return healthyVoterCount(status, rs.conf()) > status.votingMembersCount / 2;
};
const electionDeadline = Date.now() + 60000;
while (!hasPrimary() && majorityIsHealthy() && Date.now() < electionDeadline) {
    sleep(2000);
}
if (hasPrimary()) {
    print('the replica set already has a primary, nothing to force');
} else if (majorityIsHealthy()) {
    print('a majority of the voting members is healthy, the replica set elects its primary itself');
} else {
    const healthy = healthyHosts();
    const config = rs.conf();
    config.members = config.members.map(({ newlyAdded, ...member }) => {
        const reachable = member.host === '{{ $instance }}' || healthy.includes(member.host);
        return { ...member, votes: reachable ? 1 : 0, priority: reachable ? 1 : 0 };
    });
    rs.reconfig(config, { force: true });
    print(`forced a configuration in which ${healthy.length} reachable member(s) vote, the others regain their votes when they rejoin`);
}
JS
has_primary() { [ "$(mongo_js 'print(rs.status().members.some((member) => member.stateStr === "PRIMARY"))')" = true ]; }
wait_for 300 has_primary
