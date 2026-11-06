<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Lifecycle\BootstrapDatabase;
use App\Actions\Databases\Recovery\ReconcileRestoredAccounts;
use App\DTOs\Databases\ServerAccounts;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Databases\OperationStopped;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->freezeSecond();
});

function restoredOrders(): Database
{
    $source = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'), restorable: true);
    $source->users()->create(['name' => 'reporting', 'password' => 'reporting-pass', 'password_encryption' => PasswordEncryption::Legacy]);
    $source->users()->create(['name' => 'late', 'password' => 'late-pass', 'password_encryption' => PasswordEncryption::Default]);
    $source->schemas()->create(['name' => 'analytics']);
    $source->schemas()->create(['name' => 'late_db']);
    Queue::fake([BootstrapDatabaseJob::class]);
    test()->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => now()->subHour()->toIso8601String()]);

    return Database::query()->where('name', 'orders-restored')->sole();
}

function listsAccounts(string $script): bool
{
    return str_contains($script, "CONCAT('user:'");
}

it('records only the users and databases the restored server has, with the source passwords', function () {
    $restored = restoredOrders();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.crt', 'server.key', 'ca.key'));
    foreach (range(1, 6) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("PRIMARY ONLINE\n")
        ->respondWhen(fn (string $server, string $script): bool => listsAccounts($script), "user:databasify\nuser:reporting\nuser:root\ndatabase:defaultdb\ndatabase:analytics\ndatabase:mysql\n");

    resolve(BootstrapDatabase::class)->handle($restored);

    $reporting = $restored->users()->where('name', 'reporting')->sole();
    expect($restored->users()->orderBy('name')->pluck('name')->all())->toBe(['databasify', 'reporting'])
        ->and($restored->schemas()->orderBy('name')->pluck('name')->all())->toBe(['analytics', 'defaultdb'])
        ->and($reporting)->password->toBe('reporting-pass')->password_encryption->toBe(PasswordEncryption::Legacy)
        ->and(collect($ssh->runs)->filter(fn (array $run): bool => listsAccounts($run['script'])))->toHaveCount(1);
});

it('always records the default database', function () {
    $restored = restoredOrders();
    markRoles($restored, NodeRole::Primary);
    fakeSsh()->respond("user:databasify\n");

    resolve(ReconcileRestoredAccounts::class)->handle($restored);

    expect($restored->users()->pluck('name')->all())->toBe(['databasify'])
        ->and($restored->schemas()->pluck('name')->all())->toBe(['defaultdb']);
});

it('creates a database on the restored server for the users it really has', function () {
    $restored = restoredOrders();
    markRoles($restored, NodeRole::Primary);
    $restored->finishOperation();
    fakeSsh()->respond("user:databasify\nuser:reporting\ndatabase:defaultdb\ndatabase:analytics\n");
    resolve(ReconcileRestoredAccounts::class)->handle($restored);
    $ssh = fakeSsh();

    resolve(CreateSchema::class)->handle($restored, 'billing');

    expect(sqlSentIn($ssh->scriptAt(0)))->toContain("TO 'databasify'@'%'")->toContain("TO 'reporting'@'%'")->not->toContain('late');
});

it('keeps every copied account and logs a failure when the restored server cannot list them', function (string $output, int $exit, string $reason) {
    $restored = restoredOrders();
    markRoles($restored, NodeRole::Primary);
    fakeSsh()->respond($output, $exit);

    resolve(ReconcileRestoredAccounts::class)->handle($restored);

    $log = $restored->serverLogs()->where('name', 'Verify restored accounts')->sole();
    expect($restored->users()->orderBy('name')->pluck('name')->all())->toBe(['databasify', 'late', 'reporting'])
        ->and($restored->schemas()->orderBy('name')->pluck('name')->all())->toBe(['analytics', 'defaultdb', 'late_db'])
        ->and($log->status)->toBe(ServerLogStatus::Failed)
        ->and($log->server_id)->toBe($restored->seed()->server_id)
        ->and($log->output)->toContain('Could not verify the users and databases of orders-restored, so it keeps every one orders has now.')->toContain($reason);
})->with([
    'script fails' => ['', 1, 'failed with exit code 1'],
    'default user missing' => ["user:reporting\ndatabase:defaultdb\n", 0, 'The list did not include the default user databasify.'],
]);

it('resets every kept user to the password and encryption its record holds', function () {
    $restored = restoredOrders();
    markRoles($restored, NodeRole::Primary);
    $ssh = fakeSsh()->respond("user:databasify\nuser:reporting\ndatabase:defaultdb\n");

    resolve(ReconcileRestoredAccounts::class)->handle($restored);

    expect(sqlSentIn($ssh->scriptAt(1)))
        ->toContain("ALTER USER 'reporting'@'%' IDENTIFIED WITH mysql_native_password BY 'reporting-pass';")
        ->toContain("ALTER USER 'databasify'@'%' IDENTIFIED WITH caching_sha2_password BY '{$restored->settings->defaultPassword()}';")
        ->not->toContain('late')
        ->and($restored->serverLogs()->where('name', 'Verify restored accounts')->exists())->toBeFalse();
});

it('keeps every copied account and logs a failure when the restored users cannot be reset', function () {
    $restored = restoredOrders();
    markRoles($restored, NodeRole::Primary);
    fakeSsh()->respond("user:databasify\nuser:reporting\ndatabase:defaultdb\n")->respond('', 1);

    resolve(ReconcileRestoredAccounts::class)->handle($restored);

    expect($restored->users()->orderBy('name')->pluck('name')->all())->toBe(['databasify', 'late', 'reporting'])
        ->and($restored->schemas()->orderBy('name')->pluck('name')->all())->toBe(['analytics', 'defaultdb', 'late_db'])
        ->and($restored->serverLogs()->where('name', 'Verify restored accounts')->sole()->output)->toContain('Could not verify the users and databases of orders-restored')->toContain('failed with exit code 1');
});

it('leaves databases that were not restored alone', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->users()->create(['name' => 'reporting', 'password' => 'x']);
    $ssh = fakeSsh();

    resolve(ReconcileRestoredAccounts::class)->handle($database);

    expect($ssh->runs)->toBe([])
        ->and($database->users()->count())->toBe(2);
});

it('lists the users and databases of each engine without its reserved ones', function (string $engine, string $query, string $output, ServerAccounts $expected) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    $ssh = fakeSsh()->respond($output);

    expect($database->engineAccounts()->listAccounts())->toEqual($expected)
        ->and($ssh->scriptAt(0))->toContain($query)
        ->and(bashErrorsIn($ssh->scriptAt(0)))->toBe('');
})->with([
    'mysql' => ['mysql', 'FROM mysql.user', "user:root\nuser:databasify\nuser:clusteradmin\nuser:reporting\ndatabase:mysql\ndatabase:defaultdb\ndatabase:mysql_innodb_cluster_metadata\n", new ServerAccounts(['databasify', 'reporting'], ['defaultdb'])],
    'mariadb' => ['mariadb', 'FROM mysql.user', "user:mariadb.sys\nuser:databasify\nuser:sst\ndatabase:databasify\ndatabase:defaultdb\ndatabase:sys\n", new ServerAccounts(['databasify'], ['defaultdb'])],
    'postgres' => ['postgres', 'FROM pg_roles', "user:postgres\nuser:databasify\nuser:patroni\ndatabase:postgres\ndatabase:defaultdb\ndatabase:analytics\n", new ServerAccounts(['databasify'], ['defaultdb', 'analytics'])],
    'mongodb' => ['mongodb', 'usersInfo: 1', "user:root\nuser:pbm\nuser:databasify\ndatabase:admin\ndatabase:local\ndatabase:defaultdb\ndatabase:defaultdb\n", new ServerAccounts(['databasify'], ['defaultdb'])],
]);

it('lets a stop end the account check instead of reporting the accounts as unverified', function () {
    $restored = restoredOrders();
    $restored->seed()->update(['role' => NodeRole::Primary]);
    $restored->requestStop();
    fakeSsh();

    expect(fn () => resolve(ReconcileRestoredAccounts::class)->handle($restored))->toThrow(OperationStopped::class);

    expect($restored->serverLogs()->where('name', 'Verify restored accounts')->exists())->toBeFalse();
});
