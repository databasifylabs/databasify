@include('ssh.engines.mongodb.helpers')
role=$(own_role)
echo "role:$role"
mongo_run 120 <<'JS' || true
@include('ssh.engines.mongodb.cluster.horizons')
print('version:' + db.version());
const wanted = JSON.parse(require('fs').readFileSync('{{ $membersFile }}', 'utf8') || '[]');
if (db.hello().isWritablePrimary && wanted.length > 1 && db.adminCommand({ replSetGetConfig: 1, commitmentStatus: true }).commitmentStatus) {
    const secondaries = rs.status().members.filter((member) => member.health === 1 && member.stateStr === 'SECONDARY').map((member) => member.name);
    const config = rs.conf();
    const drifted = config.members.find((member) => {
        const want = wanted.find((other) => other.host === member.host);
        return want !== undefined && secondaries.includes(member.host) && (member.votes !== want.votes || member.priority !== want.priority);
    });
    if (drifted !== undefined) {
        const want = wanted.find((other) => other.host === drifted.host);
        config.members = config.members.map(({ newlyAdded, ...member }) => member.host === drifted.host ? { ...member, votes: want.votes, priority: want.priority } : member);
        rs.reconfig(config);
        print(`restored the vote of ${drifted.host}`);
    }
}
if (db.hello().isWritablePrimary && horizonsDrifted(rs.conf(), wanted) && db.adminCommand({ replSetGetConfig: 1, commitmentStatus: true }).commitmentStatus) {
    rs.reconfig(withHorizons(rs.conf(), wanted));
    print('published the public member names');
}
const rwFile = '{{ $rwConcernFile }}';
const rwWanted = require('fs').existsSync(rwFile) ? JSON.parse(require('fs').readFileSync(rwFile, 'utf8')) : {};
if (db.hello().isWritablePrimary && rwWanted.defaultReadConcern !== undefined) {
    const current = db.adminCommand({ getDefaultRWConcern: 1 });
    if (current.defaultReadConcern?.level !== rwWanted.defaultReadConcern.level || current.defaultWriteConcern?.w !== rwWanted.defaultWriteConcern.w) {
        db.adminCommand({ setDefaultRWConcern: 1, ...rwWanted });
        print(`restored the default read concern ${rwWanted.defaultReadConcern.level} and write concern ${rwWanted.defaultWriteConcern.w}`);
    }
}
JS
