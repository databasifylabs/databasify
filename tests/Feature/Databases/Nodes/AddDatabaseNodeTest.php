<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\DTOs\Databases\NewDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use App\Rules\ClusterName;
use Illuminate\Support\Facades\Queue;

it('joins a read-only node, turns on sentinel and keeps a single member database single', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $installed = $database->image();
    withImage('valkey', '9.9.9');
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();

    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server, NodeKind::ReadOnly, 'reports'));
    $operation = $database->fresh()->operation;
    $reader = $database->nodes()->readOnly()->sole();
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'existing-ca', 'ca.key' => 'existing-ca-key'])]);
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'));
    foreach (range(1, 9) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    runJob(new AddDatabaseNodeJob($database, $reader));
    $settings = $database->fresh()->settings;

    expect($operation)->toBe('Add Read-only node reports')
        ->and($reader->name)->toBe('reports')
        ->and($ssh->scriptAt(0))->toContain('subjectAltName=')->toContain(base64_encode('existing-ca-key'))->toContain($reader->server->public_ip)->not->toContain($database->seed()->server->public_ip)
        ->and($settings->nodeCertificate($reader->id, 'server.pem'))->toBe('server.pem-content')
        ->and($settings->nodeCertificate($reader->id, 'ca.key'))->toBeNull()
        ->and($settings->certificate('server.pem'))->toBe('')
        ->and($ssh->runs[1]['server'])->toBe($database->seed()->server->name)
        ->and($ssh->scriptAt(1))->toContain('set peers')->toContain($reader->privateIp())
        ->and($ssh->runs[2]['server'])->toBe($reader->server->name)
        ->and($ssh->scriptAt(2))->toContain('pulled')
        ->and($ssh->runs[3]['server'])->toBe($database->seed()->server->name)
        ->and(fileWrittenIn($ssh->scriptAt(3), '/srv/valkey/sentinel/sentinel.conf'))->toContain("sentinel monitor main {$database->seed()->privateIp()} 6379 2\n")
        ->and($ssh->scriptAt(4))->toContain('databasify ctl enable --now databasify-valkey-sentinel')
        ->and($ssh->runs[5]['server'])->toBe($reader->server->name)
        ->and(fileWrittenIn($ssh->scriptAt(5), '/srv/valkey/conf/valkey.conf'))->toContain("replica-priority 0\n")->toContain("replicaof {$database->seed()->privateIp()} 6379\n")
        ->and($ssh->scriptAt(5))->toContain(base64_encode('server.pem-content'))
        ->and($ssh->scriptAt(5))->toContain("image='{$installed->reference()}'")->not->toContain('9.9.9')
        ->and($ssh->scriptAt(6))->toContain('no trusted sources')
        ->and($ssh->runs[9]['server'])->toBe($database->seed()->server->name)
        ->and($ssh->scriptAt(9))->toContain('SENTINEL SET main quorum 2')
        ->and(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'reloaded certificates')))->toBeEmpty()
        ->and($database->fresh()->mode)->toBe(DatabaseMode::Single)
        ->and($database->fresh()->operation)->toBeNull();
});

it('frees the server again when a node fails to join', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $ssh = fakeSsh()->respond('failed to copy: connection reset by peer', 1);

    expect(fn () => runJob(new AddDatabaseNodeJob($database, $database->nodes()->where('server_id', $server->id)->sole())))
        ->toThrow(RemoteScriptFailed::class)
        ->and($database->nodes()->count())->toBe(1)
        ->and(Server::query()->withoutDatabase()->whereKey($server->id)->exists())->toBeTrue()
        ->and($ssh->scriptAt(2))->toContain('docker rm -v -f databasify-valkey')
        ->and($database->fresh()->operation)->toBeNull();
});

it('requires a unique DigitalOcean-style name for read-only nodes', function (string $name, string $message) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    withReadOnlyNode($database, ['name' => 'reports']);
    Queue::fake([AddDatabaseNodeJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id, 'kind' => 'read_only', 'name' => $name])
        ->assertSessionHasErrors(['name' => $message]);

    Queue::assertNothingPushed();
})->with([
    'missing' => ['', 'The name field is required when kind is read_only.'],
    'too short' => ['ab', ClusterName::FORMAT],
    'starts with a digit' => ['9reports', ClusterName::FORMAT],
    'ends with a dash' => ['reports-', ClusterName::FORMAT],
    'too long' => [str_repeat('a', 44), ClusterName::FORMAT],
    'private prefix' => ['private-reports', "Names can't start with private- or replica-, those prefixes are used for private and replica hostnames."],
    'replica prefix' => ['replica-cache', "Names can't start with private- or replica-, those prefixes are used for private and replica hostnames."],
    'node certificate name' => ['node-12', 'Names like node-12 are reserved for node certificates.'],
    'a database name' => ['cache', 'cache is already used by another database or read-only node.'],
    'another node name' => ['reports', 'reports is already used by another database or read-only node.'],
]);

it('adds a named read-only node from the database page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $server->id, 'kind' => 'read_only', 'name' => 'reports'])->assertRedirect();

    $reader = $database->nodes()->readOnly()->sole();
    expect($reader)->name->toBe('reports')->server_id->toBe($server->id)->position->toBe(1)
        ->and($database->fresh()->operation)->toBe('Add Read-only node reports');
    Queue::assertPushed(AddDatabaseNodeJob::class, fn (AddDatabaseNodeJob $job): bool => $job->node->is($reader));
});

it('adds nodes only from the private network of the database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $seed = $database->seed()->server;
    $elsewhere = Server::factory()->ready()->onNetwork(Network::factory()->create(['cidr' => '10.200.0.0/20']))->create(['name' => 'far']);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $elsewhere->id, 'kind' => 'read_only'])
        ->assertSessionHasErrors(['server_id' => "far is not on {$seed->name}'s private network shared (10.0.0.0/8)."]);

    $seed->update(['network_id' => null]);
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id])
        ->assertSessionHasErrors(['server_id' => "{$seed->name} has no private network. Standby and read-only nodes need one shared with it."]);

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('validates the server, kind and name of a new node', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $free = Server::factory()->ready()->create();

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $database->seed()->server_id])->assertSessionHasErrors('server_id');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->create()->id])->assertSessionHasErrors('server_id');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'kind' => 'witness'])->assertSessionHasErrors('kind');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'name' => 'reports'])->assertSessionHasErrors('name');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'kind' => 'read_only', 'name' => 'Reports'])->assertSessionHasErrors('name');

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses to add a node while the database is busy', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $database->startOperation('Upgrade');

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id])->assertSessionHasErrors('database');

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('adds a standby on a server with less than 1 GB of memory', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $database->seed()->server->update(['memory_mb' => 480]);
    $small = Server::factory()->ready()->create(['memory_mb' => 480]);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $small->id])->assertSessionHasNoErrors();

    expect($database->nodes()->members()->pluck('server_id'))->toContain($small->id);
});
