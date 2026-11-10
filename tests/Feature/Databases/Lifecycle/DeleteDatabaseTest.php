<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\Actions\Databases\Lifecycle\TearDownDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeServerConnection;

it('erases data only when asked while deleting', function () {
    $kept = idleDatabase('valkey', 1, DatabaseMode::Single);
    $erased = idleDatabase('valkey', 1, DatabaseMode::Single, 'gone');
    $ssh = fakeEngine();

    resolve(TearDownDatabase::class)->handle($kept, eraseData: false, force: false);
    resolve(TearDownDatabase::class)->handle($erased, eraseData: true, force: false);

    expect(hostRemovesIn($ssh))->toBe([['databaseId' => $kept->id, 'erase' => false], ['databaseId' => $erased->id, 'erase' => true]])
        ->and(Database::query()->count())->toBe(0)
        ->and(DatabaseNode::query()->count())->toBe(0);
});

it('keeps the database when a node cannot be cleaned up unless forced', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([DeleteDatabaseJob::class]);
    fakeSsh()->failWith(SshConnectionFailed::onServer($database->seed()->server, 'connect timed out'));

    resolve(DeleteDatabase::class)->handle($database);
    Queue::assertPushed(DeleteDatabaseJob::class);

    expect(fn () => runJob(new DeleteDatabaseJob($database, eraseData: false, force: false)))->toThrow(SshConnectionFailed::class)
        ->and(Database::query()->whereKey($database->id)->exists())->toBeTrue()
        ->and($database->fresh()->operation)->toBeNull();

    resolve(TearDownDatabase::class)->handle($database, eraseData: false, force: true);

    expect(Database::query()->whereKey($database->id)->exists())->toBeFalse();
});

it('deletes a database from its page and frees its servers', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    $ssh = fakeEngine();

    $this->delete(projectUrl("/databases/{$database->id}"), ['erase_data' => true])->assertRedirect(projectUrl('/databases'));

    expect(Database::query()->exists())->toBeFalse()
        ->and(hostRemovesIn($ssh))->toBe([['databaseId' => $database->id, 'erase' => true]])
        ->and(Server::query()->withoutDatabase()->whereKey($server->id)->exists())->toBeTrue();
});

it('passes the erase and force choices on to the delete job', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([DeleteDatabaseJob::class]);

    $this->delete(projectUrl("/databases/{$database->id}"), ['force' => true])->assertRedirect(projectUrl('/databases'));

    expect($database->fresh()->operation)->toBe('Delete database');
    Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job): bool => $job->database->is($database) && ! $job->eraseData && $job->force);
});

it('refuses to delete a busy or migrating database', function () {
    $this->actingAs(User::factory()->create());
    $database = migratingDatabase('mysql');
    Queue::fake([DeleteDatabaseJob::class]);
    $database->startOperation('Upgrade');

    $this->delete(projectUrl("/databases/{$database->id}"))->assertSessionHasErrors(['database' => "{$database->name} is busy with Upgrade, try again when it finishes."]);

    $database->finishOperation();
    migrationOf($database, MigrationMode::Continuous);

    $this->delete(projectUrl("/databases/{$database->id}"))->assertSessionHasErrors(['database' => "{$database->name} is migrating data in, finish or stop the migration first."]);

    Queue::assertNothingPushed();
    expect(Database::query()->whereKey($database->id)->exists())->toBeTrue();
});

it('removes the database dns records while deleting', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    fakeEngine();

    resolve(TearDownDatabase::class)->handle($database->fresh() ?? $database, eraseData: false, force: false);

    expect(Database::query()->count())->toBe(0);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))->toHaveCount(4);
});

it('deletes the database even when cloudflare refuses to remove its records, and says so on the server', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 403)]);
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $server = $database->seed()->server;
    fakeEngine();

    resolve(TearDownDatabase::class)->handle($database->fresh() ?? $database, eraseData: false, force: false);

    expect(Database::query()->count())->toBe(0)
        ->and($server->serverLogs()->where('name', 'Remove DNS records')->sole())
        ->status->toBe(ServerLogStatus::Failed)
        ->database_id->toBeNull()
        ->output->toContain('delete them by hand');
});

/**
 * @return list<mixed>
 */
function hostRemovesIn(FakeServerConnection $ssh): array
{
    return array_values(collect($ssh->runs)->keys()
        ->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host remove '))
        ->map(fn (int $index): mixed => json_decode($ssh->engineRequestAt($index), true))
        ->all());
}
