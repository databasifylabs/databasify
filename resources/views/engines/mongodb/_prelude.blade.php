const env = process.env;
function emit(line) { print(line); }
function result(value) { emit('DATABASIFY_RESULT ' + EJSON.stringify(value, { relaxed: true })); }
function fail(code, message) { emit('DATABASIFY_ERROR ' + code + '\n' + message); quit(3); }
function failure(code, message) { const error = new Error(message); error.databasify = code; return error; }
function codeOf(error) {
  if (error && error.databasify) return error.databasify;
  const code = error && error.code;
  if (code === 10107 || code === 13435) return 'not_primary';
  if (code === 11000 || code === 51003 || code === 51002) return 'already_exists';
  const name = String((error && error.name) || '');
  const message = String((error && error.message) || error);
  if (name.includes('MongoNetwork') || name.includes('ServerSelection') || message.includes('ECONNREFUSED') || message.includes('Server selection timed out')) return 'unavailable';
  return 'internal';
}
function hasCode(error, code) { return Boolean(error) && error.code === code; }
function run(database, command) {
  const reply = db.getSiblingDB(database).runCommand(command);
  if (reply && reply.ok === 0) {
    const error = new Error(reply.errmsg || 'command failed');
    error.code = reply.code;
    throw error;
  }
  return reply;
}
function authenticate() {
  if (env.MONGO_AUTH === 'root') db.getSiblingDB('admin').auth({ user: env.MONGO_USER || 'root', pwd: env.MONGO_SECRET });
  if (env.MONGO_AUTH === 'member') db.getSiblingDB('local').auth({ user: '__system', pwd: env.MONGO_SECRET, mechanism: 'SCRAM-SHA-256' });
}
function main(body) {
  try {
    authenticate();
    body();
  } catch (error) {
    fail(codeOf(error), String((error && error.message) || error));
  }
}
