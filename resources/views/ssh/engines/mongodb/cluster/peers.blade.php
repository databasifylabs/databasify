const peerPassword = encodeURIComponent(require('fs').readFileSync('{{ $rootPasswordFile }}', 'utf8').trim());
const peer = (host) => connect(`mongodb://root:${peerPassword}@${host}/admin?tls=true&tlsCAFile={{ $directory }}/tls/ca.crt&tlsAllowInvalidHostnames=true&directConnection=true&serverSelectionTimeoutMS=5000`);
const settle = (check, seconds) => {
    for (let waited = 0; waited < seconds; waited += 2) {
        try {
            if (check()) {
                return true;
            }
        } catch (error) {
        }
        sleep(2000);
    }
    return false;
};
const reconfigure = (edit) => {
    const config = rs.conf();
    config.members.forEach((member) => delete member.newlyAdded);
    edit(config);
    rs.reconfig(config);
};
