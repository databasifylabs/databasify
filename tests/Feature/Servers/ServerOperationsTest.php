<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerOperation;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('refuses reboots and updates on a busy database or its primary', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 2);
    [$primary, $standby] = $database->nodes()->with('server')->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    Queue::fake([UpdateServerPackagesJob::class, RebootServerJob::class]);

    $this->post(projectUrl("/servers/{$primary->server->id}/operations/reboot"))->assertSessionHasErrors('server');
    $this->post(projectUrl("/servers/{$primary->server->id}/operations/update-packages"))->assertSessionHasErrors(['server' => "{$primary->server->name} is the primary of {$database->name}, fail over first."]);
    $this->post(projectUrl("/servers/{$standby->server->id}/operations/reboot"))->assertSessionHasNoErrors();

    Queue::assertPushed(RebootServerJob::class, 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);
    $this->post(projectUrl("/servers/{$standby->server->id}/operations/update-packages"))->assertSessionHasErrors('database');
});

it('refuses work on a server that is not ready', function () {
    Queue::fake([ProvisionServerJob::class, UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->create();

    $this->post(projectUrl("/servers/{$server->id}/operations/reboot"))->assertSessionHasErrors('server');
    Queue::assertNothingPushed();
});

it('retries setup of a failed server', function () {
    Queue::fake([ProvisionServerJob::class, UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->create(['status' => ServerStatus::Failed]);

    $this->post(projectUrl("/servers/{$server->id}/provision"))->assertRedirect();

    expect($server->fresh()->status)->toBe(ServerStatus::Pending);
    Queue::assertPushed(ProvisionServerJob::class, fn (ProvisionServerJob $job) => $job->server->is($server));
});

it('queues each operation on a ready server', function (ServerOperation $operation) {
    Queue::fake([UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/servers/{$server->id}/operations/{$operation->value}"))->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertPushed($operation->jobFor($server)::class, fn (UpdateServerPackagesJob|RebootServerJob $job) => $job->server->is($server));
})->with(ServerOperation::cases());

it('returns not found for an unknown operation', function () {
    Queue::fake([UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/servers/{$server->id}/operations/format-disk"))->assertNotFound();
    Queue::assertNothingPushed();
});

it('refuses to set up a server that is already provisioning', function () {
    Queue::fake([ProvisionServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning]);

    $this->post(projectUrl("/servers/{$server->id}/provision"))->assertSessionHasErrors('server');
    Queue::assertNothingPushed();
});

it('refreshes the server facts from the server page', function () {
    fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":8,"memory_mb":16000,"disk_gb":160}'."\n");
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create(['cpus' => 2]);

    $this->post(projectUrl("/servers/{$server->id}/refresh"))->assertRedirect();

    expect($server->fresh()?->cpus)->toBe(8);
});

it('refuses operations on a server that is not ready', function () {
    Queue::fake([UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->create(['name' => 'db-1']);

    $this->post(projectUrl("/servers/{$server->id}/operations/reboot"))->assertSessionHasErrors(['server' => 'db-1 is not ready yet.']);
    Queue::assertNothingPushed();
});
