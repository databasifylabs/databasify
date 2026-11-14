<?php

declare(strict_types=1);

use App\Engines\Contracts\Accounts;
use App\Engines\Data\Grant;
use App\Enums\Databases\GrantAccess;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

beforeEach(function () {
    $this->local = localScripts()
        ->reply('FROM information_schema.SCHEMATA', '["app","mysql","shop","sys"]')
        ->reply("FROM mysql.user WHERE Host = '%'", '["app_user","databasify_repl","root"]');
});

function familyNode(string $engine, string $line): DatabaseNode
{
    return idleDatabase($engine, 1, name: uniqid('db'), versionLine: $line)->nodes()->sole();
}

it('creates a MySQL user with exactly the SQL go-sql-driver interpolates', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '0');

    familyNode('mysql', '8.4')->driver()->accounts()->createUser("o'b\"x\\`y", "p'w\n--; DROP\x1a", [new Grant('shop', GrantAccess::Write), new Grant('app', GrantAccess::Read)], null);

    expect($this->local->statements())->toContain(
        "CREATE USER 'o\\'b\\\"x\\\\`y'@'%' IDENTIFIED WITH caching_sha2_password BY 'p\\'w\\n--; DROP\\Z' REQUIRE SSL",
        "REVOKE ALL PRIVILEGES ON `app`.* FROM 'o\\'b\\\"x\\\\`y'@'%'",
        "REVOKE ALL PRIVILEGES ON `shop`.* FROM 'o\\'b\\\"x\\\\`y'@'%'",
        "GRANT ALL PRIVILEGES ON `shop`.* TO 'o\\'b\\\"x\\\\`y'@'%'",
        "GRANT SELECT, SHOW VIEW ON `app`.* TO 'o\\'b\\\"x\\\\`y'@'%'",
    )
        ->and(implode("\n", $this->local->statements()))->not->toContain('`mysql`.*');
});

it('alters an existing user without requiring SSL again', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '1');

    familyNode('mysql', '9.7')->driver()->accounts()->createUser('app_user', 'pw', [], null);

    expect($this->local->statements())->toContain("ALTER USER 'app_user'@'%' IDENTIFIED WITH caching_sha2_password BY 'pw'");
});

it('offers native passwords on MySQL 8.4 only', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '0');

    familyNode('mysql', '8.4')->driver()->accounts()->createUser('app_user', 'pw', [], 'mysql_native_password');

    expect($this->local->statements())->toContain("CREATE USER 'app_user'@'%' IDENTIFIED WITH mysql_native_password BY 'pw' REQUIRE SSL")
        ->and(fn () => familyNode('mysql', '9.7')->driver()->accounts()->createUser('app_user', 'pw', [], 'mysql_native_password'))
        ->toThrow(InvalidEngineRequest::class, 'password encryption "mysql_native_password" is not offered');
});

it('identifies MariaDB users the way the Go engine does on each line', function (string $line, ?string $encryption, string $identified) {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '0');

    familyNode('mariadb', $line)->driver()->accounts()->createUser('app_user', "p'w", [], $encryption);

    expect($this->local->statements())->toContain("CREATE USER 'app_user'@'%' {$identified} REQUIRE SSL");
})->with([
    'default' => ['10.11', null, "IDENTIFIED BY 'p\\'w'"],
    'ed25519' => ['10.11', 'ed25519', "IDENTIFIED VIA ed25519 USING PASSWORD('p\\'w')"],
    'parsec' => ['11.8', 'parsec', "IDENTIFIED VIA parsec USING PASSWORD('p\\'w')"],
]);

it('refuses parsec before MariaDB 11.6', function () {
    expect(fn () => familyNode('mariadb', '11.4')->driver()->accounts()->createUser('app_user', 'pw', [], 'parsec'))
        ->toThrow(InvalidEngineRequest::class, 'password encryption "parsec" is not offered');
});

it('refuses reserved names before running anything', function (string $engine, Closure $call, string $message) {
    expect(fn () => $call(familyNode($engine, $engine === 'mysql' ? '8.4' : '11.8')->driver()->accounts()))->toThrow(InvalidEngineRequest::class, $message)
        ->and($this->local->statements())->toBe([]);
})->with([
    'mysql root' => ['mysql', fn (Accounts $accounts) => $accounts->createUser('root', 'pw', [], null), 'root is reserved'],
    'mariadb sst user' => ['mariadb', fn (Accounts $accounts) => $accounts->dropUser('databasify_sst'), 'databasify_sst is reserved'],
    'mariadb writer database' => ['mariadb', fn (Accounts $accounts) => $accounts->createDatabase('databasify'), 'databasify is reserved'],
    'reserved grant' => ['mysql', fn (Accounts $accounts) => $accounts->grant('app_user', [new Grant('mysql', GrantAccess::Read)], false), 'mysql is reserved'],
]);

it('refuses a NUL byte before running anything', function () {
    expect(fn () => familyNode('mysql', '8.4')->driver()->accounts()->setPassword('app_user', "p\0w", null))->toThrow(InvalidArgumentException::class, 'Engine script values cannot contain a NUL byte.')
        ->and($this->local->statements())->toBe([]);
});

it('refuses a grant on a database that does not exist with the Go wording', function () {
    expect(fn () => familyNode('mysql', '8.4')->driver()->accounts()->createUser('app_user', 'pw', [new Grant('nope', GrantAccess::Read)], null))
        ->toThrow(EngineNotFound::class, 'database "nope" does not exist');

    expect(implode("\n", $this->local->statements()))->not->toContain('CREATE USER');
});

it('sets a password only for a user that exists', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '0');

    expect(fn () => familyNode('mariadb', '12.3')->driver()->accounts()->setPassword('ghost', 'pw', null))
        ->toThrow(EngineNotFound::class, 'user "ghost" does not exist');
});

it('changes the password of an existing user', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '1');

    familyNode('mariadb', '12.3')->driver()->accounts()->setPassword('app_user', 'new', 'ed25519');

    expect($this->local->statements())->toContain("ALTER USER 'app_user'@'%' IDENTIFIED VIA ed25519 USING PASSWORD('new')");
});

it('grants replication on top of database access', function () {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', '1');

    familyNode('mysql', '8.4')->driver()->accounts()->grant('app_user', [new Grant('shop', GrantAccess::Read)], true);

    expect($this->local->statements())->toContain("GRANT SELECT, SHOW VIEW ON `shop`.* TO 'app_user'@'%'", "GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'app_user'@'%'");
});

it('drops a database after revoking it from every user, ignoring a missing grant', function () {
    $this->local->reply('SELECT COALESCE(JSON_ARRAYAGG(User), JSON_ARRAY()) FROM mysql.db', '["a","b"]')
        ->reply("FROM 'a'@'%'", 'ERROR 1141 (42000) at line 1: There is no such grant defined for user \'a\' on host \'%\'');

    familyNode('mysql', '8.4')->driver()->accounts()->dropDatabase('shop');

    expect($this->local->statements())->toContain("REVOKE ALL PRIVILEGES ON `shop`.* FROM 'b'@'%'", 'DROP DATABASE IF EXISTS `shop`');
});

it('creates a database by its quoted name', function () {
    familyNode('mariadb', '11.4')->driver()->accounts()->createDatabase('my`db');

    expect($this->local->statements())->toContain('CREATE DATABASE IF NOT EXISTS `my``db`');
});

it('reports a create the server refuses as already existing', function () {
    $this->local->reply('CREATE DATABASE', "ERROR 1007 (HY000) at line 1: Can't create database 'shop'; database exists");

    expect(fn () => familyNode('mysql', '8.4')->driver()->accounts()->createDatabase('shop'))->toThrow(EngineAlreadyExists::class, "Can't create database 'shop'; database exists");
});

it('drops only a user that exists', function (string $count, bool $dropped) {
    $this->local->reply('SELECT COUNT(*) FROM mysql.user', $count);

    familyNode('mysql', '8.4')->driver()->accounts()->dropUser('app_user');

    expect(in_array("DROP USER IF EXISTS 'app_user'@'%'", $this->local->statements(), true))->toBe($dropped);
})->with(['missing' => ['0', false], 'present' => ['1', true]]);

it('lists users and databases without the reserved ones', function (string $engine) {
    $accounts = familyNode($engine, $engine === 'mysql' ? '8.4' : '11.8')->driver()->accounts()->list();

    expect($accounts->users)->toBe(['app_user'])->and($accounts->databases)->toBe(['app', 'shop']);
})->with(['mysql', 'mariadb']);
