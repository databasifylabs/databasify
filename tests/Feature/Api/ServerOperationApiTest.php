<?php

declare(strict_types=1);

use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\ApiKey;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;

test('an operation is queued on a ready server', function (string $operation, string $job) {
    Queue::fake([$job]);
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/operations/{$operation}")
        ->assertAccepted()
        ->assertJsonPath('data.id', $server->id);

    Queue::assertPushed($job);
})->with([
    'update packages' => ['update-packages', UpdateServerPackagesJob::class],
    'reboot' => ['reboot', RebootServerJob::class],
]);

test('an operation on a server that is not ready is refused', function () {
    Queue::fake([RebootServerJob::class]);
    $server = Server::factory()->create(['name' => 'db-1']);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/operations/reboot")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['server' => 'db-1 is not ready yet.']);
});

test('an unknown operation is not found', function () {
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/operations/format-disk")->assertNotFound();
});

test('a server is provisioned again', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/provision")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(ProvisionServerJob::class);
});

test('provisioning a server already being set up is refused', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = Server::factory()->create(['name' => 'db-1', 'status' => ServerStatus::Provisioning]);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/provision")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['server' => 'db-1 is already being set up.']);
});

test('refreshing reads the server facts', function () {
    $server = Server::factory()->ready()->create(['cpus' => 2]);
    $this->ssh()->replyTo('/etc/os-release', '{"os":"Ubuntu 24.04 LTS","id":"ubuntu","version_id":"24.04","selinux":"Disabled","cpus":8,"memory_mb":16384,"disk_gb":160,"subnets":[]}'."\n");
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/refresh")
        ->assertOk()
        ->assertJsonPath('data.cpus', 8);
});

test('the private network of a server changes', function () {
    $server = Server::factory()->ready()->onNetwork(null)->create();
    $network = Network::factory()->for($server->project)->create(['cidr' => '10.10.0.0/16']);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->putJson("/api/v1/servers/{$server->id}/network", ['network' => $network->id, 'private_ip' => '10.10.0.7'])
        ->assertOk()
        ->assertJsonPath('data.network_id', $network->id)
        ->assertJsonPath('data.private_ip', '10.10.0.7');
});
