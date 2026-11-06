const healthyVoterCount = (status, config) => {
    const voters = config.members.filter((member) => member.votes > 0).map((member) => member.host);
    const healthy = status.members.filter((member) => member.health === 1 && voters.includes(member.name));
    const syncing = healthy.filter((member) => ['STARTUP', 'STARTUP2'].includes(member.stateStr)).length;
    const notYetVoting = Math.max(0, voters.length - status.votingMembersCount);
    return healthy.length - Math.min(syncing, notYetVoting);
};
