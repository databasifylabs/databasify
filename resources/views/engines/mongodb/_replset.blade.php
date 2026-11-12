function numberOf(value) {
  if (value === undefined || value === null) return 0;
  if (typeof value === 'object' && typeof value.toNumber === 'function') return value.toNumber();
  const number = Number(value);
  return Number.isFinite(number) ? Math.trunc(number) : 0;
}
function membersOf(config) { return Array.isArray(config.members) ? config.members : []; }
function memberOf(config, host) { return membersOf(config).find((member) => member.host === host) || null; }
function withMembers(config, members) { return Object.assign({}, config, { members: members, version: numberOf(config.version) + 1 }); }
function isVoter(member) { return member.votes === undefined || member.votes === null || numberOf(member.votes) > 0; }
function votersOf(config) { return membersOf(config).filter(isVoter).length; }
function withMember(config, host) {
  if (memberOf(config, host)) return [config, false];
  const next = membersOf(config).reduce((highest, member) => Math.max(highest, numberOf(member._id) + 1), 0);
  return [withMembers(config, membersOf(config).concat([{ _id: next, host: host, votes: 0, priority: 0 }])), true];
}
function withVote(config, host, secondary) {
  const member = memberOf(config, host);
  if (!member || !secondary || numberOf(member.votes) > 0 || votersOf(config) >= 7) return [config, false];
  return [withMembers(config, membersOf(config).map((existing) => existing.host === host ? { _id: existing._id, host: host, votes: 1, priority: 1 } : existing)), true];
}
function withoutVote(config, host) {
  const member = memberOf(config, host);
  if (!member || numberOf(member.votes) === 0) return [config, false];
  return [withMembers(config, membersOf(config).map((existing) => existing.host === host ? { _id: existing._id, host: host, votes: 0, priority: 0 } : existing)), true];
}
function votingAlone(member) {
  const alone = Object.assign({}, member, { votes: 1, priority: 1 });
  delete alone.hidden;
  return alone;
}
function withPriority(config, host, priority) {
  return withMembers(config, membersOf(config).map((member) => member.host === host ? Object.assign({}, member, { priority: priority }) : member));
}
function withoutHost(config, host) { return withMembers(config, membersOf(config).filter((member) => member.host !== host)); }
function selfOf(status) { return (status.members || []).find((member) => member.self) || {}; }
function tooStale(status) {
  const self = selfOf(status);
  const message = String(self.infoMessage || '');
  return self.stateStr === 'RECOVERING' && (message.includes('RS102') || message.toLowerCase().includes('too stale'));
}
function hasPrimary(status) { return (status.members || []).some((member) => member.stateStr === 'PRIMARY'); }
function healthyVoters(config, status) {
  return (status.members || []).filter((member) => {
    const voter = memberOf(config, member.name);
    return numberOf(member.health) === 1 && voter && isVoter(voter);
  }).length;
}
function runOn(admin, command) {
  const reply = admin.runCommand(command);
  if (reply && reply.ok === 0) {
    const error = new Error(reply.errmsg || 'command failed');
    error.code = reply.code;
    throw error;
  }
  return reply;
}
function connectTo(host, authenticated) {
  const connection = new Mongo('mongodb://' + host + '/?directConnection=true&tls=true&tlsAllowInvalidCertificates=true&serverSelectionTimeoutMS=5000');
  const admin = connection.getDB('admin');
  if (authenticated) admin.auth({ user: 'root', pwd: env.MONGO_SECRET });
  return { connection: connection, admin: admin };
}
function peer(host) { return connectTo(host, true).admin; }
function withLocal(authenticated, body) {
  const local = connectTo('127.0.0.1:25060', authenticated);
  try {
    return body(local.admin);
  } finally {
    local.connection.close();
  }
}
function primaryOf(hosts) {
  for (const host of hosts) {
    try {
      const reached = connectTo(host, true);
      if (runOn(reached.admin, { hello: 1 }).isWritablePrimary) return { admin: reached.admin, host: host };
      reached.connection.close();
    } catch (error) {}
  }
  throw failure('unavailable', 'no member of the replica set is primary yet');
}
function configOf(admin) { return runOn(admin, { replSetGetConfig: 1 }).config; }
function reconfig(admin, config, force) { return runOn(admin, { replSetReconfig: config, force: force }); }
function local() { return db.getSiblingDB('admin'); }
function retry(seconds, label, attempt) {
  const deadline = Date.now() + seconds * 1000;
  let last = null;
  while (true) {
    try {
      return attempt();
    } catch (error) {
      if (error && error.databasify && error.databasify !== 'unavailable') throw error;
      last = error;
    }
    if (Date.now() >= deadline) throw new Error('the server was not ready within ' + label + '; last attempt: ' + String((last && last.message) || last));
    sleep(2000);
  }
}
function until(seconds, label, check) {
  const deadline = Date.now() + seconds * 1000;
  while (true) {
    let done = false;
    try {
      done = check();
    } catch (error) {
      if (error && error.databasify) throw error;
    }
    if (done) return done;
    if (Date.now() >= deadline) throw new Error('the server was not ready within ' + label);
    sleep(2000);
  }
}
function hosts() { return env.HOSTS.split(',').filter((host) => host !== ''); }
