<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use App\Rules\ClusterName;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('offers only ready servers that are not in a database', function () {
    Queue::fake([BootstrapDatabaseJob::class, AddDatabaseNodeJob::class]);
    $this->actingAs(User::factory()->create());
    Server::factory()->ready()->create(['name' => 'free']);
    Server::factory()->create(['name' => 'pending']);

    $this->get('/databases/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/create')
        ->has('servers.data', 1)
        ->where('servers.data.0.name', 'free')
        ->has('engines', 6)
        ->where('engines.0.max_standbys', 2));
});

it('creates a database from a primary and its standbys', function () {
    Queue::fake([BootstrapDatabaseJob::class, AddDatabaseNodeJob::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $first, $second] = Server::factory()->ready()->count(3)->create()->pluck('id')->all();

    $this->post('/databases', ['name' => 'main', 'engine' => 'valkey', 'primary_server_id' => $primary, 'standby_server_ids' => [$first, $second]])
        ->assertRedirect('/databases/'.Database::query()->sole()->id);

    $database = Database::query()->sole();
    expect($database->mode)->toBe(DatabaseMode::HighAvailability)
        ->and($database->nodes()->get()->map(fn ($node): array => [$node->server_id, $node->position, $node->kind])->all())->toBe([
            [$primary, 0, NodeKind::Member],
            [$first, 1, NodeKind::Member],
            [$second, 2, NodeKind::Member],
        ]);
    Queue::assertPushed(BootstrapDatabaseJob::class);
});

it('creates a single primary, or a primary with one standby', function (array $standbys, DatabaseMode $mode) {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(2)->create()->pluck('id')->all();

    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_map(fn (int $index): string => $ids[$index], $standbys)])
        ->assertSessionHasNoErrors();

    expect(Database::query()->sole())->mode->toBe($mode)
        ->and(Database::query()->sole()->nodes()->members()->count())->toBe(1 + count($standbys));
})->with([
    'primary only' => [[], DatabaseMode::Single],
    'one standby' => [[1], DatabaseMode::HighAvailability],
]);

it('validates the primary and the standbys', function () {
    Queue::fake([BootstrapDatabaseJob::class, AddDatabaseNodeJob::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $standby] = Server::factory()->ready()->count(2)->create()->pluck('id')->all();
    $pending = Server::factory()->create();

    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql'])
        ->assertSessionHasErrors('primary_server_id');
    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $pending->id])
        ->assertSessionHasErrors('primary_server_id');
    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $primary, 'standby_server_ids' => [$pending->id]])
        ->assertSessionHasErrors('standby_server_ids.0');

    expect(Database::query()->exists())->toBeFalse();
});

it('takes each server only once', function (Closure $roles) {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    [$primary, $other] = Server::factory()->ready()->count(2)->create()->pluck('id')->all();

    $this->post('/databases', ['name' => 'main', 'engine' => 'valkey', 'primary_server_id' => $primary, ...$roles($primary, $other)])
        ->assertSessionHasErrors(['primary_server_id' => 'Pick each server only once.']);
})->with([
    'primary as standby' => [fn (string $primary, string $other): array => ['standby_server_ids' => [$primary]]],
    'standby twice' => [fn (string $primary, string $other): array => ['standby_server_ids' => [$other, $other]]],
]);

it('keeps standbys on the primary private network', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $primary = Server::factory()->ready()->onNetwork(Network::factory()->create(['name' => 'blr1', 'cidr' => '10.104.0.0/20']))->create(['name' => 'db-01']);
    $elsewhere = Server::factory()->ready()->onNetwork(Network::factory()->create(['cidr' => '10.200.0.0/20']))->create(['name' => 'db-02']);
    $isolated = Server::factory()->ready()->onNetwork(null)->create(['name' => 'db-03']);

    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $primary->id, 'standby_server_ids' => [$elsewhere->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => "db-02 is not on db-01's private network blr1 (10.104.0.0/20)."]);
    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $isolated->id, 'standby_server_ids' => [$primary->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => 'db-03 has no private network. Standby and read-only nodes need one shared with it.']);
    $this->post('/databases', ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $isolated->id])
        ->assertSessionHasNoErrors();

    expect(Database::query()->sole()->seed()->server_id)->toBe($isolated->id);
});

it('refuses more than eight standbys', function () {
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(10)->create()->pluck('id')->all();

    $this->post('/databases', ['name' => 'big', 'engine' => 'valkey', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_slice($ids, 1, 9)])
        ->assertSessionHasErrors('standby_server_ids');
});

it('records the default user and database only for engines that manage accounts', function () {
    $valkey = newDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $mysql = newDatabase('mysql', 1, DatabaseMode::Single);

    expect($mysql->users()->pluck('name')->all())->toBe([$mysql->settings->secret('DB_USERNAME')])
        ->and($mysql->schemas()->pluck('name')->all())->toBe([$mysql->settings->secret('DB_DATABASE')])
        ->and($mysql->status)->toBe(DatabaseStatus::Creating)
        ->and($mysql->version_line)->toBe(Database::providerFor('mysql')->versions()->firstWhere('isDefault', true)?->value)
        ->and($mysql->operation)->toBe('Create MySQL database')
        ->and($valkey->users()->exists())->toBeFalse()
        ->and($valkey->schemas()->exists())->toBeFalse();
    Queue::assertPushed(BootstrapDatabaseJob::class, fn (BootstrapDatabaseJob $job): bool => $job->database->is($mysql));
});

it('stores the chosen version line and leaves the domain for later', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();

    $this->post('/databases', ['name' => 'cache', 'engine' => 'valkey', 'version_line' => '8', 'primary_server_id' => $server->id])
        ->assertRedirect('/databases/'.Database::query()->sole()->id);

    $database = Database::query()->sole();
    expect($database->version_line)->toBe('8')
        ->and($database->dns_zone_id)->toBeNull();
});

it('stores the newest image of its line once, when it is created', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    withImage('valkey', '8.1.9');
    withImage('valkey', '8.1.10', 'sha256:'.str_repeat('8', 64));
    withImage('valkey', '9.1.2');

    $this->post('/databases', ['name' => 'cache', 'engine' => 'valkey', 'version_line' => '8', 'primary_server_id' => $server->id])->assertRedirect('/databases/'.Database::query()->sole()->id);
    withImage('valkey', '8.1.11');

    expect(Database::query()->sole()->image())->toEqual(new EngineImage('ghcr.io/databasifylabs/databasify-valkey', 'sha256:'.str_repeat('8', 64), '8.1.10'));
});

it('creates nothing when the image registry cannot be reached', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    imageRegistry()->goDown();

    $this->post('/databases', ['name' => 'cache', 'engine' => 'valkey', 'primary_server_id' => $server->id])
        ->assertSessionHasErrors(['version_line' => 'The image registry for ghcr.io/databasifylabs/databasify-valkey could not be reached. Try again in a moment.']);

    expect(Database::query()->count())->toBe(0)
        ->and(DatabaseNode::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('validates the database name, engine and version line', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    idleDatabase('valkey', 1, DatabaseMode::Single, 'taken');
    $server = Server::factory()->ready()->create();
    $valid = ['name' => 'main', 'engine' => 'mysql', 'primary_server_id' => $server->id];

    $this->post('/databases', [...$valid, 'name' => 'Main_DB'])->assertSessionHasErrors(['name' => ClusterName::FORMAT]);
    $this->post('/databases', [...$valid, 'name' => '9db'])->assertSessionHasErrors(['name' => ClusterName::FORMAT]);
    $this->post('/databases', [...$valid, 'name' => 'private-x'])->assertSessionHasErrors('name');
    $this->post('/databases', [...$valid, 'name' => 'node-3'])->assertSessionHasErrors(['name' => 'Names like node-12 are reserved for node certificates.']);
    $this->post('/databases', [...$valid, 'name' => 'taken'])->assertSessionHasErrors(['name' => 'taken is already used by another database or read-only node.']);
    withReadOnlyNode(Database::query()->sole(), ['name' => 'reports']);
    $this->post('/databases', [...$valid, 'name' => 'reports'])->assertSessionHasErrors('name');
    $this->post('/databases', [...$valid, 'engine' => 'oracle'])->assertSessionHasErrors('engine');
    $this->post('/databases', [...$valid, 'version_line' => '5.7'])->assertSessionHasErrors('version_line');

    expect(Database::query()->count())->toBe(1);
});

it('runs a single-node database on a 512 MB server but lets standbys run on 1 GB servers', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $small = Server::factory()->ready()->create(['name' => 'small', 'memory_mb' => 480]);
    $large = Server::factory()->ready()->create(['name' => 'large', 'memory_mb' => 1967]);
    $other = Server::factory()->ready()->create(['name' => 'other', 'memory_mb' => 3925]);
    $tooSmall = 'Every node of a database with standbys needs at least 1 GB, so it can only run a single-node database.';

    $this->post('/databases', ['name' => 'cluster', 'engine' => 'mysql', 'primary_server_id' => $small->id, 'standby_server_ids' => [$large->id]])
        ->assertSessionHasErrors(['primary_server_id' => "small has 480 MB of memory. {$tooSmall}"]);
    $this->post('/databases', ['name' => 'cluster', 'engine' => 'mysql', 'primary_server_id' => $large->id, 'standby_server_ids' => [$small->id]])
        ->assertSessionHasErrors(['standby_server_ids.0' => "small has 480 MB of memory. {$tooSmall}"]);
    $this->post('/databases', ['name' => 'cluster', 'engine' => 'mysql', 'primary_server_id' => $large->id, 'standby_server_ids' => [$other->id]])
        ->assertSessionHasNoErrors();
    $this->post('/databases', ['name' => 'single', 'engine' => 'mysql', 'primary_server_id' => $small->id])
        ->assertSessionHasNoErrors();

    $oneGb = Server::factory()->ready()->create(['name' => 'one-gb', 'memory_mb' => 961]);
    $oneGbStandby = Server::factory()->ready()->create(['name' => 'one-gb-standby', 'memory_mb' => 961]);
    $this->post('/databases', ['name' => 'small-cluster', 'engine' => 'mysql', 'primary_server_id' => $oneGb->id, 'standby_server_ids' => [$oneGbStandby->id]])
        ->assertSessionHasNoErrors();

    expect(Database::query()->pluck('name')->sort()->values()->all())->toBe(['cluster', 'single', 'small-cluster']);
});

it('gives every new sql database the databasify user and the defaultdb database', function (string $engine) {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());

    $this->post('/databases', ['name' => 'orders', 'engine' => $engine, 'primary_server_id' => Server::factory()->ready()->create()->id])->assertRedirect();

    $database = Database::query()->sole();
    expect($database->users()->pluck('name')->all())->toBe(['databasify'])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb'])
        ->and($database->settings->secret('DB_USERNAME'))->toBe('databasify')
        ->and($database->settings->secret('DB_DATABASE'))->toBe('defaultdb');
})->with(['mysql', 'mariadb', 'postgres']);

it('refuses a third standby when creating a database', function () {
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->actingAs(User::factory()->create());
    $ids = Server::factory()->ready()->count(4)->create()->pluck('id')->all();

    $this->post('/databases', ['name' => 'main', 'engine' => 'postgres', 'primary_server_id' => $ids[0], 'standby_server_ids' => array_slice($ids, 1)])
        ->assertSessionHasErrors('standby_server_ids');
});
