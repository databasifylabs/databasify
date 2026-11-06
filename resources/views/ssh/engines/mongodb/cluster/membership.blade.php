@include('ssh.engines.mongodb.helpers')
(umask 077 && cat > {{ $membersFile }} <<'MEMBERS'
{!! json_encode($members, JSON_UNESCAPED_SLASHES) !!}
MEMBERS
)
if ! writable_primary; then
    echo "not the primary, membership is reconciled there"
    exit 0
fi
mongo_run 1800 <<'JS'
@include('ssh.engines.mongodb.cluster.peers')
@include('ssh.engines.mongodb.cluster.horizons')
const wanted = {!! json_encode($members, JSON_UNESCAPED_SLASHES) !!};
const hosts = wanted.map((member) => member.host);
let changes = 0;
rs.conf().members.filter((member) => !hosts.includes(member.host)).forEach((member) => {
    reconfigure((config) => { config.members = config.members.filter((other) => other.host !== member.host); });
    changes += 1;
});
wanted.forEach((want) => {
    const member = rs.conf().members.find((other) => other.host === want.host);
    if (member === undefined) {
        rs.add({ host: want.host, votes: want.votes, priority: want.priority, ...horizonsOf(want.alias) });
        changes += 1;
    } else if (member.votes !== want.votes || member.priority !== want.priority) {
        reconfigure((config) => Object.assign(config.members.find((other) => other.host === want.host), { votes: want.votes, priority: want.priority }));
        changes += 1;
    }
});
if (horizonsDrifted(rs.conf(), wanted)) {
    rs.reconfig(withHorizons(rs.conf(), wanted));
    changes += 1;
}
print(`membership reconciled with ${changes} change(s)`);
JS
