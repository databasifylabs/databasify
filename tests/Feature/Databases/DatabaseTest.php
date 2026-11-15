<?php

declare(strict_types=1);

use App\Contracts\Images\ImageRegistry;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeImageRegistry;

function publishImages(array $sdkByRelease): void
{
    app()->instance(ImageRegistry::class, new FakeImageRegistry($sdkByRelease));
}

test('databases page is displayed', function () {
    $database = Database::factory()->create(['name' => 'orders']);

    $this->actingAs(User::factory()->create())
        ->get(route('databases.index', $database->project_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('databases/index')->has('databases.data', 1));
});

test('databases can be filtered by engine and status', function () {
    $project = Project::factory()->create();
    Database::factory()->for($project)->create(['name' => 'orders']);
    Database::factory()->for($project)->engine('mysql', '8.4')->create(['name' => 'shop']);
    Database::factory()->for($project)->status(DatabaseStatus::Failed)->create(['name' => 'broken']);

    $this->actingAs(User::factory()->create())
        ->get(route('databases.index', ['project' => $project, 'engine' => 'postgres', 'status' => 'ready']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('databases.data', 1)
            ->where('databases.data.0.name', 'orders')
            ->where('filters', ['search' => '', 'status' => 'ready', 'engine' => 'postgres']));
});

test('unknown engine filters are ignored', function () {
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('databases.index', ['project' => $database->project_id, 'engine' => "postgres' OR 1=1 --"]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.engine', null)->has('databases.data', 1));
});

test('create database page offers only free ready servers', function () {
    $project = Project::factory()->create();
    $free = Server::factory()->ready()->for($project)->create();
    Server::factory()->for($project)->create();
    DatabaseNode::factory()->for(Database::factory()->for($project))->for(Server::factory()->ready()->for($project))->create();

    $this->actingAs(User::factory()->create())
        ->get(route('databases.create', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('databases/create')
            ->has('engines')
            ->has('servers.data', 1)
            ->where('servers.data.0.id', $free->id));
});

test('a database is created on the newest image with a supported sdk', function () {
    Queue::fake([CreateDatabaseJob::class]);
    publishImages(['18.2' => '0.1.0', '18.1' => '0.2.2', '17.6' => '0.2.2']);
    $server = Server::factory()->ready()->create();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $server->project_id), ['name' => 'orders', 'engine' => 'postgres', 'version_line' => '18', 'primary_server_id' => $server->id]);

    $database = Database::query()->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('databases.show', [$server->project_id, $database]));
    expect($database)
        ->status->toBe(DatabaseStatus::Pending)
        ->mode->toBe(DatabaseMode::Single)
        ->image->release->toBe('18.1')
        ->and($database->nodes()->sole()->server_id)->toBe($server->id)
        ->and($database->users()->pluck('name')->all())->toBe([$database->settings->defaultUsername()]);
    Queue::assertPushed(CreateDatabaseJob::class, fn (CreateDatabaseJob $job) => $job->database->is($database));
});

test('a database with a standby on the same private network is highly available', function () {
    Queue::fake([CreateDatabaseJob::class]);
    publishImages(['18.1' => '0.2.2']);
    $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);
    $primary = Server::factory()->ready()->onNetwork($network)->create();
    $standby = Server::factory()->ready()->onNetwork($network)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $primary->project_id), ['name' => 'orders', 'engine' => 'postgres', 'primary_server_id' => $primary->id, 'standby_server_ids' => [$standby->id]])
        ->assertSessionHasNoErrors();

    $database = Database::query()->sole();
    expect($database->mode)->toBe(DatabaseMode::HighAvailability)
        ->and($database->nodes()->orderBy('position')->pluck('server_id')->all())->toBe([$primary->id, $standby->id]);
});

test('creating a database requires a name, an engine and a server', function () {
    Queue::fake([CreateDatabaseJob::class]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $project), [])
        ->assertSessionHasErrors(['name', 'engine', 'primary_server_id']);

    Queue::assertNotPushed(CreateDatabaseJob::class);
});

test('database names follow the cluster name rules', function (string $name, string $message) {
    Database::factory()->create(['name' => 'taken-name']);
    $server = Server::factory()->ready()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $server->project_id), ['name' => $name, 'engine' => 'postgres', 'primary_server_id' => $server->id])
        ->assertSessionHasErrors(['name' => $message]);
})->with([
    'uppercase' => ['Orders', 'Names must be in lowercase, start with a letter, end with a letter or number, be between 3 and 43 characters long, and contain only letters, numbers, or dashes (-).'],
    'reserved prefix' => ['replica-orders', 'Names can\'t start with private- or replica-, those prefixes are used for private and replica hostnames.'],
    'taken' => ['taken-name', 'taken-name is already used by another database or read-only node.'],
]);

test('a database needs free ready servers, each picked once', function () {
    $busy = DatabaseNode::factory()->create()->server;
    $server = Server::factory()->ready()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('databases.store', $server->project_id), ['name' => 'orders', 'engine' => 'postgres', 'primary_server_id' => $busy->id])
        ->assertSessionHasErrors(['primary_server_id' => 'Pick a ready server that is not already part of a database.']);

    $this->actingAs($user)
        ->post(route('databases.store', $server->project_id), ['name' => 'orders', 'engine' => 'postgres', 'primary_server_id' => $server->id, 'standby_server_ids' => [$server->id]])
        ->assertSessionHasErrors(['primary_server_id' => 'Pick each server only once.']);
});

test('a standby must share the primary private network', function () {
    $primary = Server::factory()->ready()->onNetwork(Network::factory()->create(['name' => 'office', 'cidr' => '10.10.0.0/16']))->create(['name' => 'db-1']);
    $standby = Server::factory()->ready()->onNetwork(Network::factory()->create(['cidr' => '10.20.0.0/16']))->create(['name' => 'db-2']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $primary->project_id), ['name' => 'orders', 'engine' => 'postgres', 'primary_server_id' => $primary->id, 'standby_server_ids' => [$standby->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => "db-2 is not on db-1's private network office (10.10.0.0/16)."]);
});

test('a version line without a supported image is refused', function () {
    Queue::fake([CreateDatabaseJob::class]);
    publishImages(['18.2' => '0.1.0']);
    $server = Server::factory()->ready()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.store', $server->project_id), ['name' => 'orders', 'engine' => 'postgres', 'version_line' => '18', 'primary_server_id' => $server->id])
        ->assertSessionHasErrors(['version_line' => 'PostgreSQL 18.2 is built on SDK 0.1.0; this Databasify supports ^0.2.2 || ^1.0.']);

    expect(Database::query()->exists())->toBeFalse();
    Queue::assertNotPushed(CreateDatabaseJob::class);
});

test('database page is displayed', function () {
    $node = DatabaseNode::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('databases.show', [$node->database->project_id, $node->database]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('databases/show')->where('database.data.id', $node->database_id));
});

test('a database is queued for deletion', function () {
    Queue::fake([DeleteDatabaseJob::class]);
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.destroy', [$database->project_id, $database]), ['erase_data' => true, 'delete_backups' => true])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('databases.index', $database->project_id));

    expect($database->refresh()->status)->toBe(DatabaseStatus::Deleting);
    Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job) => $job->eraseData && $job->deleteBackups && ! $job->force && ! $job->setupFailed);
});

test('a busy database is not deleted', function () {
    Queue::fake([DeleteDatabaseJob::class]);
    $database = Database::factory()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.destroy', [$database->project_id, $database]))
        ->assertSessionHasErrors(['database' => 'orders is busy upgrading, try again when it finishes.']);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Upgrading);
    Queue::assertNotPushed(DeleteDatabaseJob::class);
});

test('deleting a database whose setup failed tells the job so', function () {
    Queue::fake([DeleteDatabaseJob::class]);
    $database = Database::factory()->status(DatabaseStatus::Failed)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.destroy', [$database->project_id, $database]))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job) => $job->setupFailed);
});
