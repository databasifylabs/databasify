@include('ssh.engines.mongodb.helpers')
mongo_js '
    const users = db.getSiblingDB("admin").runCommand({ usersInfo: 1 }).users;
    const listed = db.adminCommand({ listDatabases: 1, nameOnly: true }).databases.map((database) => database.name);
    const granted = users.flatMap((user) => user.roles.map((role) => role.db));
    users.forEach((user) => print("user:" + user.user));
    [...new Set([...listed, ...granted])].forEach((name) => print("database:" + name));
'
