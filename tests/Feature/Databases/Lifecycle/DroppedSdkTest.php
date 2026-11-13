<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Actions\Databases\Accounts\DeleteSchema;
use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Failover\FailoverDatabase;
use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Actions\Databases\Pools\CreateConnectionPool;
use App\Actions\Databases\Recovery\BackUpNow;
use App\Actions\Databases\Recovery\RestoreToPointInTime;
use App\Actions\Databases\Recovery\UpdateRecoverySchedule;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewConnectionPool;
use App\DTOs\Databases\NewDatabaseNode;
use App\DTOs\Recovery\PointInTimeRestore;
use App\DTOs\Recovery\RecoverySchedule;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\PoolMode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

const DROPPED_SDK_REFUSAL = 'main runs an engine image built on SDK 0.0.9, which this Databasify no longer supports. Wait for Databasify to publish a new image.';

beforeEach(function () {
    Queue::fake([AdvanceDatabaseFlow::class, FailoverDatabaseJob::class, RefreshRecoveryStatusJob::class]);
});

function onSdk(Database $database, ?string $sdk): Database
{
    $image = $database->image;
    $database->update(['image' => new EngineImage($image->repository, $image->digest, $image->release, $sdk)]);

    return $database->refresh();
}

it('refuses every change to a database on a dropped SDK', function (Closure $change) {
    $database = onSdk(idleDatabase('postgres', 2), '0.0.9');
    $user = $database->users()->create(['name' => 'app', 'password' => 'secret']);
    $schema = $database->schemas()->create(['name' => 'shop']);

    expect(fn () => $change($database, $user, $schema))->toThrow(ValidationException::class, DROPPED_SDK_REFUSAL);
})->with([
    'settings' => [fn (Database $database) => resolve(UpdateDatabaseConfig::class)->handle($database, ['max_connections' => '200'])],
    'retune' => [fn (Database $database) => resolve(RetuneDatabase::class)->handle($database)],
    'add a node' => [fn (Database $database) => resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode(Server::factory()->ready()->create()))],
    'remove a node' => [fn (Database $database) => resolve(RemoveDatabaseNode::class)->handle($database->nodes()->latest('id')->firstOrFail())],
    'create a user' => [fn (Database $database) => resolve(CreateDatabaseUser::class)->handle($database, 'reporting')],
    'reset a password' => [fn (Database $database, $user) => resolve(ResetDatabaseUserPassword::class)->handle($user)],
    'change a password encryption' => [fn (Database $database, $user) => resolve(ChangePasswordEncryption::class)->handle($user, 'SCRAM-SHA-256')],
    'delete a user' => [fn (Database $database, $user) => resolve(DeleteDatabaseUser::class)->handle($user)],
    'create a database' => [fn (Database $database) => resolve(CreateSchema::class)->handle($database, 'orders')],
    'delete a database' => [fn (Database $database, $user, $schema) => resolve(DeleteSchema::class)->handle($schema)],
    'create a pool' => [fn (Database $database) => resolve(CreateConnectionPool::class)->handle($database, new NewConnectionPool('shop_pool', 'shop', PoolMode::Transaction, 20))],
    'change the backup schedule' => [fn (Database $database) => resolve(UpdateRecoverySchedule::class)->handle(withRecovery($database), new RecoverySchedule(7, 3))],
]);

it('keeps backups, failover, outage recovery and delete working on a dropped SDK', function () {
    $database = onSdk(idleDatabase('postgres', 2), '0.0.9');

    resolve(BackUpNow::class)->handle(withRecovery($database));
    resolve(FailoverDatabase::class)->handle($database->refresh());
    $database->refresh()->finishOperation();
    expect(fn () => resolve(RecoverDatabase::class)->handle($database->refresh()))->not->toThrow(ValidationException::class, 'no longer supports');
    $database->refresh()->finishOperation();
    resolve(DeleteDatabase::class)->handle($database->refresh(), force: true);

    Queue::assertPushed(RefreshRecoveryStatusJob::class);
    Queue::assertPushed(FailoverDatabaseJob::class);
    expect($database->fresh())->toBeNull();
});

it('reboots the servers of a database on a dropped SDK', function () {
    $database = onSdk(idleDatabase('postgres', 1, DatabaseMode::Single), '0.0.9');

    expect(fn () => resolve(RebootDatabase::class)->handle($database))->not->toThrow(ValidationException::class, 'no longer supports');
});

it('redeploys a node of a database on a dropped SDK with its current image', function () {
    fakeEngine(fakeSsh()->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '0.0.9']));
    $database = onSdk(idleDatabase('postgres', 1, DatabaseMode::Single), '0.0.9');

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());
})->throwsNoExceptions();

it('restores a database on a dropped SDK onto the newest supported image of its line', function () {
    $source = withRecovery(onSdk(idleDatabase('postgres', 1, name: 'orders'), '0.0.9'), restorable: true);
    $target = $source->pointInTimeRecovery->latest_restorable_at->subMinute();

    $restored = inProjectDuring(fn (): Database => resolve(RestoreToPointInTime::class)->handle($source, new PointInTimeRestore('orders-restored', Server::factory()->ready()->create()->id, $target)));

    expect($restored->image->sdk)->toBe('0.2.2');
});

it('lets storage credentials rotate while a database on a dropped SDK archives there', function () {
    $this->actingAs(User::factory()->create());
    $database = withRecovery(onSdk(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'), '0.0.9'));
    $provider = $database->pointInTimeRecovery?->storageProvider;
    fakeBucket();

    $this->put(projectUrl("/storage-providers/{$provider?->id}"), [...$provider?->bucket()->location(), 'name' => 'renamed', 'path_style' => '1', 'access_key_id' => 'NEWKEY', 'secret_access_key' => 'newsecret'])
        ->assertSessionHasNoErrors();

    expect($database->fresh()->operation)->toBe('Update archive credentials');
});

it('names the plugin author of a community engine in the refusal', function () {
    $database = onSdk(idleDatabase('postgres', 1, DatabaseMode::Single), '0.0.9');
    config()->set('engine.providers.postgres.plugin', 'acme/engines');
    config()->set('engine.providers.postgres.author', 'Acme');

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class, 'Wait for Acme to publish a new image.');
});

it('upgrades a database on a dropped SDK only to an image inside the range', function () {
    $database = onSdk(idleDatabase('valkey', 1, DatabaseMode::Single), '0.0.9');
    $current = $database->image;

    $database->update(['available_image' => new EngineImage($current->repository, 'sha256:'.str_repeat('d', 64), '9.9.9', '0.3.0')]);
    expect(fn () => resolve(UpgradeDatabase::class)->handle($database->refresh()))->toThrow(ValidationException::class, 'is built on SDK 0.3.0');

    $database->update(['available_image' => new EngineImage($current->repository, 'sha256:'.str_repeat('e', 64), '9.9.9', '0.2.4')]);
    resolve(UpgradeDatabase::class)->handle($database->refresh());

    expect($database->refresh()->runsSupportedSdk())->toBeTrue();
});

it('shows the read-only banner on the database page and lists the database on the dashboard', function () {
    $this->actingAs(User::factory()->create());
    $database = onSdk(idleDatabase('valkey', 1, DatabaseMode::Single), '0.0.9');
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/show')
        ->where('sdkUnsupported', ['sdk' => '0.0.9', 'range' => '^0.2.2 || ^1.0', 'author' => 'Databasify']));

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('dashboard')
        ->where('sdkUnsupported', [['id' => $database->id, 'name' => 'main', 'sdk' => '0.0.9']]));
});

it('shows no banner for a database on a supported SDK', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sdkUnsupported', null));
});

it('lists the databases another SDK range would make read-only', function () {
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->artisan('databases:check-sdks', ['--range' => '^0.3'])
        ->expectsOutputToContain('cache runs SDK 0.2.2')
        ->assertExitCode(0);

    $this->artisan('databases:check-sdks', ['--range' => '^0.2'])
        ->expectsOutputToContain('No database would become read-only.')
        ->assertExitCode(0);
});
