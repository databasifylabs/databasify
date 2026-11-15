<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Servers\ServerStatus;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a server operation is queued', function (string $operation, string $job) {
    Queue::fake([$job]);
    $server = Server::factory()->ready()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.operations.store', [$server->project_id, $server, $operation]))
        ->assertSessionHasNoErrors();

    Queue::assertPushed($job);
})->with([
    'update packages' => ['update-packages', UpdateServerPackagesJob::class],
    'reboot' => ['reboot', RebootServerJob::class],
]);

test('server operations need a ready server', function () {
    Queue::fake([RebootServerJob::class]);
    $server = Server::factory()->create(['name' => 'db-1']);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.operations.store', [$server->project_id, $server, 'reboot']))
        ->assertSessionHasErrors(['server' => 'db-1 is not ready yet.']);

    Queue::assertNotPushed(RebootServerJob::class);
});

test('the primary of a database with a standby is not rebooted', function () {
    Queue::fake([RebootServerJob::class]);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    $primary = DatabaseNode::factory()->for($database)->create();
    DatabaseNode::factory()->for($database)->replica()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.operations.store', [$database->project_id, $primary->server, 'reboot']))
        ->assertSessionHasErrors(['server' => "{$primary->server->name} is the primary of orders, fail over first."]);

    Queue::assertNotPushed(RebootServerJob::class);
});

test('a server of a busy database is not touched', function () {
    Queue::fake([UpdateServerPackagesJob::class]);
    $database = Database::factory()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);
    $node = DatabaseNode::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.operations.store', [$database->project_id, $node->server, 'update-packages']))
        ->assertSessionHasErrors(['database' => 'orders is busy upgrading, try again when it finishes.']);

    Queue::assertNotPushed(UpdateServerPackagesJob::class);
});

test('a server can be provisioned again', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Failed]);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.provision', [$server->project_id, $server]))
        ->assertSessionHasNoErrors();

    expect($server->refresh()->status)->toBe(ServerStatus::Pending);
    Queue::assertPushed(ProvisionServerJob::class);
});

test('a server being provisioned is not provisioned twice', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = Server::factory()->create(['name' => 'db-1', 'status' => ServerStatus::Provisioning]);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.provision', [$server->project_id, $server]))
        ->assertSessionHasErrors(['server' => 'db-1 is already being set up.']);

    Queue::assertNotPushed(ProvisionServerJob::class);
});

test('refreshing a server reads its facts and flags its databases for retuning', function () {
    $node = DatabaseNode::factory()->create();
    $server = $node->server;
    $this->ssh()->replyTo('/etc/os-release', '{"os":"Ubuntu 24.04 LTS","id":"ubuntu","version_id":"24.04","selinux":"Disabled","cpus":8,"memory_mb":16384,"disk_gb":160,"subnets":[]}'."\n");

    $this->actingAs(User::factory()->create())
        ->post(route('servers.refresh', [$server->project_id, $server]))
        ->assertSessionHasNoErrors();

    expect($server->refresh())
        ->os->toBe('Ubuntu 24.04 LTS')
        ->cpus->toBe(8)
        ->memory_mb->toBe(16384)
        ->disk_gb->toBe(160)
        ->and($node->database->refresh()->retune_needed_at)->not->toBeNull();
});

test('refreshing an unreachable server shows the connection error', function () {
    $server = Server::factory()->ready()->create(['cpus' => 2]);
    $this->ssh()->failOn('/etc/os-release', SshConnectionFailed::onServer($server, 'connection refused'));

    $this->actingAs(User::factory()->create())
        ->post(route('servers.refresh', [$server->project_id, $server]))
        ->assertSessionHasErrors('server');

    expect($server->refresh()->cpus)->toBe(2);
});
