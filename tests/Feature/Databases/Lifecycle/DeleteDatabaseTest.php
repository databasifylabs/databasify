<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\Actions\Databases\Lifecycle\TearDownDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('erases data only when asked while deleting', function () {
    $kept = idleDatabase('valkey', 1, DatabaseMode::Single);
    $erased = idleDatabase('valkey', 1, DatabaseMode::Single, 'gone');
    $ssh = fakeSsh();

    resolve(TearDownDatabase::class)->handle($kept, eraseData: false, force: false);
    resolve(TearDownDatabase::class)->handle($erased, eraseData: true, force: false);

    expect($ssh->scriptAt(0))->toContain('data in $dir is kept')->not->toContain('rm -rf')
        ->and($ssh->scriptAt(2))->toContain('rm -rf "$dir"')->toContain('rm -f /etc/systemd/system/disable-thp.service /etc/sysctl.d/99-valkey.conf')
        ->and(Database::query()->count())->toBe(0)
        ->and(DatabaseNode::query()->count())->toBe(0);
});

it('keeps the database when a node cannot be cleaned up unless forced', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([DeleteDatabaseJob::class]);
    fakeSsh()->respond('ssh: connect timed out', 255)->respond('ssh: connect timed out', 255);

    resolve(DeleteDatabase::class)->handle($database);
    Queue::assertPushed(DeleteDatabaseJob::class);

    expect(fn () => runJob(new DeleteDatabaseJob($database, eraseData: false, force: false)))->toThrow(RemoteScriptFailed::class)
        ->and(Database::query()->whereKey($database->id)->exists())->toBeTrue()
        ->and($database->fresh()->operation)->toBeNull();

    resolve(TearDownDatabase::class)->handle($database, eraseData: false, force: true);

    expect(Database::query()->whereKey($database->id)->exists())->toBeFalse();
});

it('deletes a database from its page and frees its servers', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    $ssh = fakeSsh();

    $this->delete("/databases/{$database->id}", ['erase_data' => true])->assertRedirect('/databases');

    expect(Database::query()->exists())->toBeFalse()
        ->and($ssh->scriptAt(0))->toContain('rm -rf "$dir"')
        ->and(Server::query()->withoutDatabase()->whereKey($server->id)->exists())->toBeTrue();
});

it('passes the erase and force choices on to the delete job', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([DeleteDatabaseJob::class]);

    $this->delete("/databases/{$database->id}", ['force' => true])->assertRedirect('/databases');

    expect($database->fresh()->operation)->toBe('Delete database');
    Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job): bool => $job->database->is($database) && ! $job->eraseData && $job->force);
});

it('refuses to delete a busy or migrating database', function () {
    $this->actingAs(User::factory()->create());
    $database = migratingDatabase('mysql');
    Queue::fake([DeleteDatabaseJob::class]);
    $database->startOperation('Upgrade');

    $this->delete("/databases/{$database->id}")->assertSessionHasErrors(['database' => "{$database->name} is busy with Upgrade, try again when it finishes."]);

    $database->finishOperation();
    migrationOf($database, MigrationMode::Continuous);

    $this->delete("/databases/{$database->id}")->assertSessionHasErrors(['database' => "{$database->name} is migrating data in, finish or stop the migration first."]);

    Queue::assertNothingPushed();
    expect(Database::query()->whereKey($database->id)->exists())->toBeTrue();
});

it('removes the database dns records while deleting', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    fakeSsh();

    resolve(TearDownDatabase::class)->handle($database->fresh() ?? $database, eraseData: false, force: false);

    expect(Database::query()->count())->toBe(0);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))->toHaveCount(4);
});

it('deletes the database even when cloudflare refuses to remove its records, and says so on the server', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 403)]);
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $server = $database->seed()->server;
    fakeSsh();

    resolve(TearDownDatabase::class)->handle($database->fresh() ?? $database, eraseData: false, force: false);

    expect(Database::query()->count())->toBe(0)
        ->and($server->serverLogs()->where('name', 'Remove DNS records')->sole())
        ->status->toBe(ServerLogStatus::Failed)
        ->database_id->toBeNull()
        ->output->toContain('delete them by hand');
});

it('stops the engine container and removes it with its images only when the data is erased', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $erase = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render();
    $keep = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(false))->render();

    expect($erase)->toContain("if container_running databasify-mariadb; then\n    docker stop databasify-mariadb > /dev/null\n")
        ->toContain('docker rm -v -f databasify-mariadb')->toContain('xargs -r docker rmi -f')
        ->and($keep)->toContain('docker stop databasify-mariadb')->toContain('stopped; data in $dir is kept')
        ->not->toContain('docker rm')->not->toContain('rm -rf "$dir"');
});

it('resets the host tuning of the engine when a database is erased', function (string $engine, string $removed) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render();

    expect($script)->toContain($removed)->not->toContain('apt-get')->not->toContain('userdel '.Database::providerFor($engine)->systemUser());
})->with([
    'valkey' => ['valkey', 'rm -f /etc/systemd/system/disable-thp.service /etc/sysctl.d/99-valkey.conf'],
    'redis' => ['redis', 'rm -f /etc/systemd/system/disable-thp.service /etc/sysctl.d/99-redis.conf'],
    'postgres' => ['postgres', 'rm -f /etc/sysctl.d/99-postgres.conf'],
    'mysql' => ['mysql', 'rm -f /etc/sysctl.d/99-mysql.conf'],
]);
