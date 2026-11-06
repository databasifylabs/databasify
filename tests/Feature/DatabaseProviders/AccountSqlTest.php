<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('writes mysql account statements that grant per database', function () {
    expect(accountSql('mysql', 'create-user', ['name' => 'reports', 'password' => 'secret', 'databases' => ['app', 'shop']]))->toBe("CREATE USER IF NOT EXISTS 'reports'@'%' IDENTIFIED BY 'secret';\nGRANT ALL PRIVILEGES ON `app`.* TO 'reports'@'%';\nGRANT ALL PRIVILEGES ON `shop`.* TO 'reports'@'%';\n")
        ->and(accountSql('mysql', 'create-user', ['name' => 'reports', 'password' => 'secret', 'databases' => []]))->toBe("CREATE USER IF NOT EXISTS 'reports'@'%' IDENTIFIED BY 'secret';\n")
        ->and(accountSql('mysql', 'drop-user', ['name' => 'reports', 'databases' => ['app']]))->toBe("DROP USER IF EXISTS 'reports'@'%';\n")
        ->and(accountSql('mysql', 'reset-password', ['name' => 'reports', 'password' => 'fresh']))->toBe("ALTER USER 'reports'@'%' IDENTIFIED BY 'fresh';\n")
        ->and(accountSql('mysql', 'create-database', ['name' => 'shop', 'users' => ['app']]))->toBe("CREATE DATABASE IF NOT EXISTS `shop`;\nGRANT ALL PRIVILEGES ON `shop`.* TO 'app'@'%';\n")
        ->and(accountSql('mysql', 'drop-database', ['name' => 'shop']))->toBe("DROP DATABASE IF EXISTS `shop`;\n");
});

it('writes postgres password resets and database grants', function () {
    expect(accountSql('postgres', 'reset-password', ['name' => 'reports', 'password' => 'fresh']))->toBe("ALTER ROLE \"reports\" PASSWORD 'fresh';\n")
        ->and(accountSql('postgres', 'create-database', ['name' => 'shop', 'users' => ['app']]))->toContain("GRANT ALL PRIVILEGES ON DATABASE \"shop\" TO \"app\";\n\\connect \"shop\"\nGRANT ALL ON SCHEMA public TO \"app\";")
        ->and(accountSql('postgres', 'drop-user', ['name' => 'reports']))->toBe("\\connect postgres\nDROP ROLE IF EXISTS \"reports\";\n");
});

it('creates or updates admin users with scram-sha-256 and per database roles', function () {
    $statement = accountSql('mongodb', 'create-user', ['name' => 'reports', 'password' => "it's-secret", 'databases' => ['app', 'shop']]);

    expect($statement)->toContain('const user = "reports";')
        ->toContain('pwd: "it\'s-secret"')
        ->toContain("roles: [\"app\",\"shop\"].flatMap((db) => ['readWrite', 'dbAdmin'].map((role) => ({ role, db })))")
        ->toContain("mechanisms: ['SCRAM-SHA-256']")
        ->toContain("existing === null\n")
        ->toContain("{ w: 'majority' }")
        ->and(accountSql('mongodb', 'create-user', ['name' => 'reports', 'password' => 'secret', 'databases' => []]))->toContain('roles: [].flatMap(')
        ->and(javascriptErrorsIn($statement))->toBe('');
});

it('keeps the oplog read right of an existing mongodb user when it updates the roles', function () {
    $statement = accountSql('mongodb', 'create-user', ['name' => 'databasify', 'password' => 'secret', 'databases' => ['defaultdb', 'shop']]);
    $stubs = 'let updated = null; const admin = { getUser: () => ({ roles: [{ role: "readWrite", db: "defaultdb", minFcv: "" }, { role: "read", db: "local" }] }), updateUser: (user, account) => { updated = account.roles; } };
        globalThis.db = { getSiblingDB: () => admin }; process.on("exit", () => console.log(JSON.stringify(updated)));';

    $result = Process::input($stubs."\n".$statement)->run(['node']);

    expect(json_decode($result->output(), true))->toBe([
        ['role' => 'readWrite', 'db' => 'defaultdb'], ['role' => 'dbAdmin', 'db' => 'defaultdb'],
        ['role' => 'readWrite', 'db' => 'shop'], ['role' => 'dbAdmin', 'db' => 'shop'],
        ['role' => 'read', 'db' => 'local'],
    ]);
});

it('grants a new database to every existing user without creating it', function () {
    $statement = accountSql('mongodb', 'create-database', ['name' => 'shop', 'users' => ['app', 'reports']]);

    expect($statement)->toContain("['readWrite', 'dbAdmin'].map((role) => ({ role, db: \"shop\" }))")
        ->toContain('["app","reports"].forEach((user) => admin.grantRolesToUser(user, roles')
        ->not->toContain('createCollection')
        ->and(javascriptErrorsIn($statement))->toBe('');
});

it('drops a database and revokes the grants that pointed at it', function () {
    $statement = accountSql('mongodb', 'drop-database', ['name' => 'shop']);

    expect($statement)->toContain('db.getSiblingDB(name).dropDatabase(')
        ->toContain('revokeRolesFromUser(grant.user, grant.roles')
        ->and(javascriptErrorsIn($statement))->toBe('');
});

it('drops users only when they exist and resets passwords in place', function () {
    expect(accountSql('mongodb', 'drop-user', ['name' => 'reports', 'databases' => ['app']]))->toContain('admin.getUser(user) === null || admin.dropUser(user')
        ->and(accountSql('mongodb', 'reset-password', ['name' => 'reports', 'password' => 'fresh']))->toContain('updateUser("reports", { pwd: "fresh"')
        ->and(javascriptErrorsIn(accountSql('mongodb', 'drop-user', ['name' => 'reports']).accountSql('mongodb', 'reset-password', ['name' => 'reports', 'password' => 'fresh'])))->toBe('');
});

it('grants the default user replication rights so it can be a migration source', function (string $engine, string $line, string $grant) {
    $database = idleDatabase($engine, 1, versionLine: $line);
    $username = $database->settings->defaultUsername();

    expect($database->provider()->accounts()?->defaultAccountSql())->toContain(str_replace('{user}', $username, $grant));
})->with([
    'mysql 8.4' => ['mysql', '8.4', "GRANT REPLICATION SLAVE, REPLICATION CLIENT, BACKUP_ADMIN ON *.* TO '{user}'@'%';"],
    'mysql 9.7' => ['mysql', '9.7', "GRANT REPLICATION SLAVE, REPLICATION CLIENT, BACKUP_ADMIN ON *.* TO '{user}'@'%';"],
    'mariadb 11.4' => ['mariadb', '11.4', "GRANT REPLICATION SLAVE, BINLOG MONITOR, RELOAD ON *.* TO '{user}'@'%';"],
    'mariadb 11.8' => ['mariadb', '11.8', "GRANT REPLICATION SLAVE, BINLOG MONITOR, RELOAD ON *.* TO '{user}'@'%';"],
    'postgres 17' => ['postgres', '17', 'ALTER ROLE "{user}" REPLICATION;'],
    'postgres 18' => ['postgres', '18', 'ALTER ROLE "{user}" REPLICATION;'],
    'mongodb 7.0' => ['mongodb', '7.0', "grantRolesToUser(\"{user}\", [{ role: 'read', db: 'local' }], { w: 'majority' });"],
    'mongodb 8.0' => ['mongodb', '8.0', "grantRolesToUser(\"{user}\", [{ role: 'read', db: 'local' }], { w: 'majority' });"],
    'mongodb 9.0' => ['mongodb', '9.0', "grantRolesToUser(\"{user}\", [{ role: 'read', db: 'local' }], { w: 'majority' });"],
]);
