@include('ssh.engines.mongodb.helpers')
mongo_run 600 <<'JS'
@include('ssh.engines.mongodb.cluster.peers')
const host = '{{ $instance }}';
const primary = rs.status().members.find((member) => member.stateStr === 'PRIMARY');
if (!rs.conf().members.some((member) => member.host === host)) {
    print(`${host} is not a member`);
} else if (primary !== undefined) {
    const writer = primary.self ? db : peer(primary.name);
    const config = writer.adminCommand({ replSetGetConfig: 1 }).config;
    config.members = config.members.filter((member) => member.host !== host).map(({ newlyAdded, ...member }) => member);
    config.version += 1;
    writer.adminCommand({ replSetReconfig: config });
    print(`removed ${host} from the replica set`);
} else {
    const config = rs.conf();
    config.members = config.members.filter((member) => member.host !== host).map(({ newlyAdded, ...member }) => member);
    rs.reconfig(config, { force: true });
    print(`no primary left, removed ${host} by forcing the configuration`);
}
JS
