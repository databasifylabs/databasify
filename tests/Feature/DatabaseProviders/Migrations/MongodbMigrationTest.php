<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationPhase;
use App\Jobs\Databases\RunMigrationJob;
use App\Models\Database;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->ssh = fakeSsh();
    $this->database = migratingDatabase('mongodb');
    $this->migration = $this->database->provider()->migration();
});

it('asks for credentials and the databases to copy, never the system ones', function () {
    expect($this->migration->form()->toArray())->toBe(['default_port' => 27017, 'credentials_required' => true, 'selects_databases' => true])
        ->and($this->migration->form()->reservedDatabases)->toBe(['admin', 'local', 'config']);
});

it('checks the source without putting the password on the command line and needs a replica set to follow it', function () {
    $this->ssh->respond("reachable=yes\nversion=8.0.4\nreplica_set=\nsize_mb=12\ndatabases=shop\n")->respond("reachable=yes\nversion=8.0.4\nreplica_set=rs0\n");

    $standalone = $this->migration->check(new MigrationSource('mongo.example.com', 27017, 'mig@rator', 'p@ss/word'));
    $replicaSet = $this->migration->check(new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret'));

    expect($standalone->facts)->toMatchArray(['version' => '8.0.4', 'size_mb' => '12', 'databases' => 'shop'])
        ->and($standalone->supportsContinuous())->toBeFalse()
        ->and($replicaSet->supportsContinuous())->toBeTrue()
        ->and($this->ssh->scriptAt(0))->not->toContain('p@ss/word')->toContain(base64_encode('mongodb://mig%40rator:p%40ss%2Fword@mongo.example.com:27017/?authSource=admin&directConnection=true&tls=true&tlsInsecure=true'));
});

it('refuses to follow a source whose user cannot read the oplog', function () {
    $this->ssh->respond("reachable=yes\nversion=9.0.4\nreplica_set=rs0\noplog=no\n")->respond("reachable=yes\nversion=9.0.4\nreplica_set=rs0\noplog=yes\n");

    $denied = $this->migration->check(new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret'));
    $readable = $this->migration->check(new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret'));

    expect($denied->blockers)->toBe(['The source user needs read on the local database (the oplog). Grant read on the local database (the oplog) to migrator on the source.'])
        ->and($readable->supportsContinuous())->toBeTrue()
        ->and($this->ssh->scriptAt(0))->toContain('getCollection("oplog.rs").find({}, { ts: 1 }).limit(1)')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('reads a missing oplog right only from an unauthorized error and treats other errors as unreachable', function (string $setName, string $error, string $expected) {
    $this->ssh->respond("reachable=yes\nversion=7.0.45\nreplica_set=rs0\noplog=yes\n");
    $this->migration->check(new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret'));
    preg_match("/--eval '(.*?)' <\\/dev\\/null/s", $this->ssh->scriptAt(0), $probe);
    $stubs = 'globalThis.print = (...values) => console.log(...values); const failure = '.$error.';
        globalThis.db = { hello: () => ({ '.$setName.' }), version: () => "7.0.45", adminCommand: () => ({ databases: [{ name: "app" }], totalSize: 0 }),
            getSiblingDB: () => ({ getCollection: () => ({ find: () => ({ limit: () => ({ toArray: () => { throw failure; } }) }) }) }) };';

    $result = Process::input($stubs."\n".$probe[1])->run(['node']);

    expect($result->output())->toBe($expected);
})->with([
    'unauthorized' => ['setName: "rs0"', 'Object.assign(new Error("not authorized on local"), { codeName: "Unauthorized" })', "reachable=yes\nversion=7.0.45\nreplica_set=rs0\nsize_mb=0\ndatabases=app\noplog=no\n"],
    'network error' => ['setName: "rs0"', 'Object.assign(new Error("connection reset"), { codeName: "HostUnreachable" })', ''],
    'a mongos, which has no oplog to probe' => ['msg: "isdbgrid"', 'Object.assign(new Error("no such collection"), { codeName: "NamespaceNotFound" })', "reachable=yes\nversion=7.0.45\nreplica_set=\nsize_mb=0\ndatabases=app\noplog=no\n"],
]);

it('refuses a mongodb target that already holds the source databases', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond("reachable=yes\nversion=8.0.4\nreplica_set=rs0\nsize_mb=1\ndatabases=shop,blog\noccupied=shop\n");

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret', databases: ['shop', 'blog']), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'These databases already hold rows here, from an earlier copy or your own data: shop.')
        ->and($this->ssh->scriptAt(0))->toContain("wanted='shop,blog'")->toContain('WANTED="$wanted" mongo_js')->toContain('findOne() !== null')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
    Queue::assertNothingPushed();
});

it('starts mongoshake from the source into the local primary for the chosen databases', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('mongo.example.com', 27017, 'migrator', 'secret', databases: ['shop', 'admin'])]);

    $this->migration->start($migration);

    $script = $this->ssh->scriptAt(0);
    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d > \"\\\$state\\/collector.conf\"/", $script, $match);
    $config = base64_decode($match[1] ?? '', true);
    expect($config)->toStartWith("conf.version = 14\n")->toContain("sync_mode = all\n")
        ->toContain("filter.namespace.white = shop\n")
        ->toContain('tunnel.address = mongodb://root:'.$this->database->settings->secret('MONGODB_ROOT_PASSWORD').'@'.$migration->host()->privateIp().":25060/admin\n")
        ->toContain("mongo_urls = mongodb://migrator:secret@mongo.example.com:27017/admin\n")
        ->toContain("mongo_ssl_root_ca_file = /srv/mongodb/tls/ca.crt\n")
        ->toContain("tunnel.mongo_ssl_root_ca_file = /srv/mongodb/tls/ca.crt\n")
        ->not->toContain('?')
        ->toContain("checkpoint.storage.db = databasify_migration\n")
        ->and($script)->toContain('databasify ctl run --quiet --remain-after-exit --unit=mongodb-migration')->toContain('-verbose=0');
});

it('reports the copy, then the lag once it follows the oplog', function () {
    $this->ssh->respond("running=true\nphase=full\ncopied=40.00% of 5 collections\n")
        ->respond("running=true\nphase=incremental\nlag=3\n")
        ->respond("running=false\nexit_code=1\nlog=auth failed\n");
    $migration = migrationOf($this->database, MigrationMode::Continuous);

    expect($this->migration->progress($migration))->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying from the source: 40.00% of 5 collections')
        ->and($this->migration->progress($migration))->phase->toBe(MigrationPhase::CaughtUp)->lagSeconds->toBe(3)
        ->and($this->migration->progress($migration))->phase->toBe(MigrationPhase::Broken)->detail->toContain('auth failed');
});

it('stops mongoshake and drops its checkpoints', function () {
    $this->migration->stop(migrationOf($this->database, MigrationMode::Continuous));

    expect($this->ssh->scriptAt(0))->toContain('databasify ctl stop mongodb-migration')->toContain("getSiblingDB('databasify_migration').dropDatabase");
});

it('reads the copy progress, then the unapplied oplog backlog once mongoshake follows the source', function (string $repl, string $full, string $expected) {
    $script = view('ssh.engines.mongodb.migrations.progress', [...Database::providerFor('mongodb')->scriptVariables(), 'container' => 'databasify-mongodb'])->render();
    $stubs = <<<'BASH'
        databasify() { case "$4" in LoadState) echo loaded ;; ActiveState) echo active ;; SubState) echo running ;; *) echo 0 ;; esac; }
        curl() { case "$*" in *9100/repl*) printf '%s' "$REPL" ;; *9101/progress*) printf '%s' "$FULL" ;; esac; }
        BASH;

    $output = Process::env(['REPL' => $repl, 'FULL' => $full])->input("set -euo pipefail\n{$stubs}\n{$script}")->run(['bash'])->throw()->output();

    expect($output)->toContain($expected);
})->with([
    'copying' => ['', '{"progress":"40.00%","total_collection_number":5}', "phase=full\ncopied=40.00% of 5 collections"],
    'idle after the copy' => ['{"lsn":{"unix":0},"lsn_ack":{"unix":0},"now":{"unix":1793188948}}', '{"progress":"100.00%"}', "phase=incremental\nlag=0"],
    'applying' => ['[{"lsn":{"unix":1793188990},"lsn_ack":{"unix":1793188986},"now":{"unix":1793189019}}]', '', "phase=incremental\nlag=4"],
]);
