@isset($self)
const SELF = @json($self);
const HOSTS = @json($hosts);
@endisset
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
function closingAfter(reached, body) {
  let outcome;
  try {
    outcome = body(reached);
  } catch (error) {
    reached.connection.close();
    throw error;
  }
  reached.connection.close();
  return outcome;
}
function withLocal(authenticated, body) {
  const outcome = closingAfter(connectTo('127.0.0.1:25060', authenticated), (local) => body(local.admin));
  return outcome;
}
function reachablePrimaryOf(hosts) {
  for (const host of hosts) {
    let reached = null;
    try {
      reached = connectTo(host, true);
      if (runOn(reached.admin, { hello: 1 }).isWritablePrimary) return { admin: reached.admin, host: host, connection: reached.connection };
    } catch (error) {}
    if (reached) reached.connection.close();
  }
  throw failure('unavailable', 'no member of the replica set is primary yet');
}
const PRIMARY_WAIT_SECONDS = 120;
function primaryOf(hosts) { return retry(PRIMARY_WAIT_SECONDS, PRIMARY_WAIT_SECONDS + 's', () => reachablePrimaryOf(hosts)); }
function withPrimary(candidates, body) {
  const outcome = closingAfter(primaryOf(candidates), body);
  return outcome;
}
function configOf(admin) { return runOn(admin, { replSetGetConfig: 1 }).config; }
const CONFIG_BEING_UPDATED = [109, 117, 308];
function configBeingUpdated(error) { return CONFIG_BEING_UPDATED.some((code) => hasCode(error, code)); }
const CONFIG_COMMIT_SECONDS = 120;
function configCommitted(admin) {
  try {
    const reply = runOn(admin, { replSetGetConfig: 1, commitmentStatus: true });
    return reply.commitmentStatus !== false;
  } catch (error) {
    return true;
  }
}
function reconfig(admin, config, force) {
  const deadline = Date.now() + CONFIG_COMMIT_SECONDS * 1000;
  while (true) {
    if (force || configCommitted(admin)) {
      try {
        const reply = runOn(admin, { replSetReconfig: config, force: force });
        return reply;
      } catch (error) {
        if (!configBeingUpdated(error)) throw error;
      }
    }
    if (Date.now() >= deadline) throw new Error('the replica set was still committing its previous config after ' + CONFIG_COMMIT_SECONDS + 's, so nothing was changed');
    sleep(2000);
  }
}
function local() { return db.getSiblingDB('admin'); }
function retry(seconds, label, attempt) {
  const deadline = Date.now() + seconds * 1000;
  let last = null;
  while (true) {
    try {
      const value = attempt();
      return value;
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
function termOf(config) { return config.term === undefined || config.term === null ? null : numberOf(config.term); }
function newerConfig(config, than) {
  const term = termOf(config);
  const otherTerm = termOf(than);
  if (term !== null && otherTerm !== null && term !== otherTerm) return term > otherTerm;
  return numberOf(config.version) > numberOf(than.version);
}
function describeConfig(config) { return 'version ' + numberOf(config.version) + (termOf(config) === null ? ' without a term' : ', term ' + termOf(config)); }
const STRANDED_RECOVERY_SECONDS = 600;
function ownConfig() {
  let own = null;
  try {
    own = withLocal(true, configOf);
  } catch (error) {}
  return own;
}
function forcedConfig(config) { return termOf(config) === null || termOf(config) === -1; }
function refuseNewerSelfListing(own, current) {
  if (forcedConfig(own) && memberOf(own, SELF) && newerConfig(own, current)) throw failure('busy', SELF + ' holds a forced replica set config (' + describeConfig(own) + ') that is newer than the config of the primary (' + describeConfig(current) + ') and lists itself, so it may hold writes the set does not have; it is not rejoined automatically. Check its data, then resync it from an empty data directory');
}
function otherHosts() { return HOSTS.filter((host) => host !== SELF); }
function refuseForcedSelfAhead(own) {
  if (!forcedConfig(own) || !memberOf(own, SELF)) return;
  let primary = null;
  let current = null;
  try {
    primary = reachablePrimaryOf(otherHosts());
    current = configOf(primary.admin);
  } catch (error) {}
  if (primary) primary.connection.close();
  if (current) refuseNewerSelfListing(own, current);
}
function servingVotersOf(admin, config) {
  return (runOn(admin, { replSetGetStatus: 1 }).members || []).filter((member) => ['PRIMARY', 'SECONDARY'].includes(member.stateStr) && memberOf(config, member.name) && isVoter(memberOf(config, member.name))).length;
}
function raiseAboveStranded(admin, own) {
  const deadline = Date.now() + CONFIG_COMMIT_SECONDS * 1000;
  while (true) {
    const seen = configOf(admin);
    if (!newerConfig(own, seen)) return false;
    if (!memberOf(seen, SELF)) throw failure('busy', SELF + ' holds a newer replica set config (' + describeConfig(own) + ') than the primary (' + describeConfig(seen) + '), and the primary does not list it; add it back to the set, then redeploy it');
    const serving = servingVotersOf(admin, seen);
    if (serving * 2 <= votersOf(seen)) throw failure('busy', SELF + ' holds a newer replica set config (' + describeConfig(own) + ') than the primary (' + describeConfig(seen) + '), but only ' + serving + ' of ' + votersOf(seen) + ' voters are primary or secondary, so the primary cannot safely raise its config version; restore a majority, then redeploy it, or resync ' + SELF + ' from an empty data directory if it is one of the missing voters');
    if (configCommitted(admin)) {
      const fresh = configOf(admin);
      if (numberOf(fresh.version) === numberOf(seen.version) && termOf(fresh) === termOf(seen)) {
        try {
          const reply = runOn(admin, { replSetReconfig: Object.assign({}, fresh, { version: numberOf(own.version) + 1 }), force: false });
          if (reply) return true;
        } catch (error) {
          if (!configBeingUpdated(error)) throw error;
        }
      }
    }
    if (Date.now() >= deadline) throw new Error('the replica set was still committing its previous config after ' + CONFIG_COMMIT_SECONDS + 's, so its version was not raised above ' + SELF);
    sleep(2000);
  }
}
function recoverStrandedSelf(own) { return withPrimary(otherHosts(), (primary) => raiseAboveStranded(primary.admin, own)); }
