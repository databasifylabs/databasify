<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Engines\EngineCatalog;
use App\Engines\Flows\CreateDatabaseFlow;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use App\Rules\ClusterName;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('offers only ready servers that are not in a database', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    Server::factory()->ready()->create(['name' => 'free']);
    Server::factory()->create(['name' => 'pending']);

    $this->get(projectUrl('/databases/create'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/create')
        ->has('servers.data', 1)
        ->where('servers.data.0.name', 'free')
        ->has('engines', 6)
        ->where('engines.0.max_standbys', 2));
});

it('creates a database from a primary and its standbys', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $first, $second] = Server::factory()->ready()->count(3)->create()->pluck('id')->all();

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'valkey', 'primary_server_id' => $primary, 'standby_server_ids' => [$first, $second]])
        ->assertRedirect(projectUrl('/databases/').Database::query()->sole()->id);

    $database = Database::query()->sole();
    expect($database->mode)->toBe(DatabaseMode::HighAvailability)
        ->and($database->nodes()->get()->map(fn ($node): array => [$node->server_id, $node->position, $node->kind])->all())->toBe([
            [$primary, 0, NodeKind::Member],
            [$first, 1, NodeKind::Member],
            [$second, 2, NodeKind::Member],
        ]);
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('creates a single primary, or a primary with one standby', function (array $standbys, DatabaseMode $mode) {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(2)->create()->pluck('id')->all();

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_map(fn (int $index): string => $ids[$index], $standbys)])
        ->assertSessionHasNoErrors();

    expect(Database::query()->sole())->mode->toBe($mode)
        ->and(Database::query()->sole()->nodes()->members()->count())->toBe(1 + count($standbys));
})->with([
    'primary only' => [[], DatabaseMode::Single],
    'one standby' => [[1], DatabaseMode::HighAvailability],
]);

it('validates the primary and the standbys', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $standby] = Server::factory()->ready()->count(2)->create()->pluck('id')->all();
    $pending = Server::factory()->create();

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql'])
        ->assertSessionHasErrors('primary_server_id');
    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $pending->id])
        ->assertSessionHasErrors('primary_server_id');
    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $primary, 'standby_server_ids' => [$pending->id]])
        ->assertSessionHasErrors('standby_server_ids.0');

    expect(Database::query()->exists())->toBeFalse();
});

it('takes each server only once', function (Closure $roles) {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $other] = Server::factory()->ready()->count(2)->create()->pluck('id')->all();

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'valkey', 'primary_server_id' => $primary, ...$roles($primary, $other)])
        ->assertSessionHasErrors(['primary_server_id' => 'Pick each server only once.']);
})->with([
    'primary as standby' => [fn (string $primary, string $other): array => ['standby_server_ids' => [$primary]]],
    'standby twice' => [fn (string $primary, string $other): array => ['standby_server_ids' => [$other, $other]]],
]);

it('keeps standbys on the primary private network', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $primary = Server::factory()->ready()->onNetwork(Network::factory()->create(['name' => 'blr1', 'cidr' => '10.104.0.0/20']))->create(['name' => 'db-01']);
    $elsewhere = Server::factory()->ready()->onNetwork(Network::factory()->create(['cidr' => '10.200.0.0/20']))->create(['name' => 'db-02']);
    $isolated = Server::factory()->ready()->onNetwork(null)->create(['name' => 'db-03']);

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $primary->id, 'standby_server_ids' => [$elsewhere->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => "db-02 is not on db-01's private network blr1 (10.104.0.0/20)."]);
    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $isolated->id, 'standby_server_ids' => [$primary->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => 'db-03 has no private network. Standby and read-only nodes need one shared with it.']);
    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $isolated->id])
        ->assertSessionHasNoErrors();

    expect(Database::query()->sole()->seed()->server_id)->toBe($isolated->id);
});

it('refuses more than eight standbys', function () {
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(10)->create()->pluck('id')->all();

    $this->post(projectUrl('/databases'), ['name' => 'big', 'engine' => 'valkey', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_slice($ids, 1, 9)])
        ->assertSessionHasErrors('standby_server_ids');
});

it('records the default user and database only for engines that manage accounts', function () {
    $valkey = newDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $mysql = newDatabase('mysql', 1, DatabaseMode::Single);

    expect($mysql->users()->pluck('name')->all())->toBe([$mysql->settings->secret('DB_USERNAME')])
        ->and($mysql->schemas()->pluck('name')->all())->toBe([$mysql->settings->secret('DB_DATABASE')])
        ->and($mysql->status)->toBe(DatabaseStatus::Creating)
        ->and($mysql->version_line)->toBe(EngineCatalog::find('mysql')->defaultLine)
        ->and($mysql->operation)->toBe('Create MySQL database')
        ->and($valkey->users()->exists())->toBeFalse()
        ->and($valkey->schemas()->exists())->toBeFalse();
    expect($mysql->flows()->sole()->flow)->toBe(CreateDatabaseFlow::class);
});

it('stores the chosen version line and leaves the domain for later', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl('/databases'), ['name' => 'cache', 'engine' => 'valkey', 'version_line' => '8', 'primary_server_id' => $server->id])
        ->assertRedirect(projectUrl('/databases/').Database::query()->sole()->id);

    $database = Database::query()->sole();
    expect($database->version_line)->toBe('8')
        ->and($database->dns_zone_id)->toBeNull();
});

it('stores the newest image of its line once, when it is created', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    withImage('valkey', '8.1.9');
    withImage('valkey', '8.1.10', 'sha256:'.str_repeat('8', 64));
    withImage('valkey', '9.1.2');

    $this->post(projectUrl('/databases'), ['name' => 'cache', 'engine' => 'valkey', 'version_line' => '8', 'primary_server_id' => $server->id])->assertRedirect(projectUrl('/databases/').Database::query()->sole()->id);
    withImage('valkey', '8.1.11');

    expect(Database::query()->sole())
        ->image->toEqual(new EngineImage('ghcr.io/databasifylabs/databasify-valkey', 'sha256:'.str_repeat('8', 64), '8.1.10', '0.2.0'))
        ->available_image->toBeNull();
});

it('refuses to create a database from an image built on an SDK outside the range', function (?string $sdk, string $named) {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    withImage('valkey', '9.0', sdk: $sdk);
    withImage('valkey', '9.0.3', sdk: $sdk);

    $this->post(projectUrl('/databases'), ['name' => 'cache', 'engine' => 'valkey', 'version_line' => '9', 'primary_server_id' => $server->id])
        ->assertSessionHasErrors(['version_line' => "Valkey 9.0.3 is built on SDK {$named}; this Databasify supports ^0.2."]);

    expect(Database::query()->count())->toBe(0)
        ->and(DatabaseNode::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'a newer minor' => ['0.3.0', '0.3.0'],
    'an image without an SDK label' => [null, 'unknown'],
]);

it('creates nothing when the image registry cannot be reached', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    imageRegistry()->goDown();

    $this->post(projectUrl('/databases'), ['name' => 'cache', 'engine' => 'valkey', 'primary_server_id' => $server->id])
        ->assertSessionHasErrors(['version_line' => 'The image registry for ghcr.io/databasifylabs/databasify-valkey could not be reached. Try again in a moment.']);

    expect(Database::query()->count())->toBe(0)
        ->and(DatabaseNode::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('validates the database name, engine and version line', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    idleDatabase('valkey', 1, DatabaseMode::Single, 'taken');
    $server = Server::factory()->ready()->create();
    $valid = ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $server->id];

    $this->post(projectUrl('/databases'), [...$valid, 'name' => 'Main_DB'])->assertSessionHasErrors(['name' => ClusterName::FORMAT]);
    $this->post(projectUrl('/databases'), [...$valid, 'name' => '9db'])->assertSessionHasErrors(['name' => ClusterName::FORMAT]);
    $this->post(projectUrl('/databases'), [...$valid, 'name' => 'private-x'])->assertSessionHasErrors('name');
    $this->post(projectUrl('/databases'), [...$valid, 'name' => 'node-3'])->assertSessionHasErrors(['name' => 'Names like node-12 are reserved for node certificates.']);
    $this->post(projectUrl('/databases'), [...$valid, 'name' => 'taken'])->assertSessionHasErrors(['name' => 'taken is already used by another database or read-only node.']);
    withReadOnlyNode(Database::query()->sole(), ['name' => 'reports']);
    $this->post(projectUrl('/databases'), [...$valid, 'name' => 'reports'])->assertSessionHasErrors('name');
    $this->post(projectUrl('/databases'), [...$valid, 'engine' => 'oracle'])->assertSessionHasErrors('engine');
    $this->post(projectUrl('/databases'), [...$valid, 'version_line' => '5.7'])->assertSessionHasErrors('version_line');

    expect(Database::query()->count())->toBe(1);
});

it('creates a database with standbys on servers with less than 1 GB of memory', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $primary = Server::factory()->ready()->create(['memory_mb' => 480]);
    $standby = Server::factory()->ready()->create(['memory_mb' => 480]);

    $this->post(projectUrl('/databases'), ['name' => 'cluster', 'engine' => 'mysql', 'primary_server_id' => $primary->id, 'standby_server_ids' => [$standby->id]])
        ->assertSessionHasNoErrors();

    expect(Database::query()->sole()->name)->toBe('cluster');
});

it('gives every new sql database the databasify user and the defaultdb database', function (string $engine) {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());

    $this->post(projectUrl('/databases'), ['name' => 'orders', 'engine' => $engine, 'primary_server_id' => Server::factory()->ready()->create()->id])->assertRedirect();

    $database = Database::query()->sole();
    expect($database->users()->pluck('name')->all())->toBe(['databasify'])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb'])
        ->and($database->settings->secret('DB_USERNAME'))->toBe('databasify')
        ->and($database->settings->secret('DB_DATABASE'))->toBe('defaultdb');
})->with(['mysql', 'mariadb', 'postgres']);

it('refuses a third standby when creating a database', function () {
    Queue::fake([AdvanceDatabaseFlow::class]);
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(4)->create()->pluck('id')->all();

    $this->post(projectUrl('/databases'), ['name' => 'main', 'engine' => 'postgres', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_slice($ids, 1)])
        ->assertSessionHasErrors('standby_server_ids');
});
