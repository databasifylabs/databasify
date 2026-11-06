@include('ssh.engines.mongodb.helpers')
if ! writable_primary; then
    echo "this node is not the primary, there is nothing to move"
    exit 1
fi
mongo_run 600 <<'JS'
@include('ssh.engines.mongodb.cluster.peers')
const target = '{{ $target }}';
const me = db.hello().me;
const others = rs.conf().members.filter((member) => member.priority > 0 && member.host !== target && member.host !== me).map((member) => member.host);
others.forEach((host) => { try { peer(host).adminCommand({ replSetFreeze: 120 }); } catch (error) { print(`could not hold back ${host}: ${error.message}`); } });
try { peer(target).adminCommand({ replSetFreeze: 0 }); } catch (error) { print(`could not end the stepdown period of ${target}: ${error.message}`); }
const releaseOthers = () => others.forEach((host) => { try { peer(host).adminCommand({ replSetFreeze: 0 }); } catch (error) { } });
let refusal = null;
try {
    db.adminCommand({ replSetStepDown: 60, secondaryCatchUpPeriodSecs: 30 });
} catch (error) {
    refusal = error;
}
const stillPrimary = () => { try { return db.hello().isWritablePrimary; } catch (error) { return false; } };
if (stillPrimary()) {
    releaseOthers();
    throw new Error(`${me} kept the primary: ${refusal?.message ?? 'the step-down had no effect'}`);
}
const targetIsPrimary = () => peer(target).hello().isWritablePrimary;
if (!settle(targetIsPrimary, 4)) {
    try { peer(target).adminCommand({ replSetStepUp: 1 }); } catch (error) { print(`asked ${target} to stand for election (${error.message})`); }
}
const moved = settle(targetIsPrimary, 90);
releaseOthers();
if (!moved) {
    throw new Error(`${target} did not become the primary`);
}
print(`${target} is the primary now`);
JS
