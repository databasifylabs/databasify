@include('ssh.engines.mongodb.helpers')
@include('ssh.databases.helpers')
mongo_run 1800 <<'JS'
@include('ssh.engines.mongodb.cluster.peers')
const me = db.hello().me;
while (rs.conf().members.length > 1) {
    if (db.hello().isWritablePrimary) {
        const other = rs.conf().members.find((member) => member.host !== me);
        reconfigure((config) => { config.members = config.members.filter((member) => member.host !== other.host); });
        continue;
    }
    const config = rs.conf();
    config.members = config.members.filter((member) => member.host === me).map(({ newlyAdded, ...member }) => ({ ...member, votes: 1, priority: 1 }));
    rs.reconfig(config, { force: true });
}
if (rs.conf().members[0].votes !== 1 || rs.conf().members[0].priority === 0) {
    reconfigure((config) => Object.assign(config.members[0], { votes: 1, priority: 1 }));
}
print(`${me} runs as a one-member replica set`);
JS
wait_for 300 writable_primary
