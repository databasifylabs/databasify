<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([ProvisionServerJob::class]);
    $this->actingAs(User::factory()->create());
});

$server = ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_password' => 'secret'];

it('adds a server on a new private network', function () use ($server) {
    $this->post('/servers', [...$server, 'network' => 'new', 'network_name' => 'fsn1', 'network_cidr' => '10.0.1.0/24', 'private_ip' => '10.0.1.2'])
        ->assertSessionHasNoErrors();

    expect(Server::query()->sole())
        ->private_ip->toBe('10.0.1.2')
        ->network->name->toBe('fsn1')
        ->network->cidr->toBe('10.0.1.0/24');
});

it('adds a server on an existing network or on none', function () use ($server) {
    $network = Network::factory()->create(['cidr' => '10.104.0.0/20']);

    $this->post('/servers', [...$server, 'network' => (string) $network->id, 'private_ip' => '10.104.0.7'])->assertSessionHasNoErrors();
    $this->post('/servers', [...$server, 'name' => 'db-2', 'public_ip' => '203.0.113.11', 'network' => '', 'private_ip' => '10.104.0.8'])->assertSessionHasNoErrors();

    expect(Server::query()->where('name', 'db-1')->sole())->network_id->toBe($network->id)->private_ip->toBe('10.104.0.7')
        ->and(Server::query()->where('name', 'db-2')->sole())->network_id->toBeNull()->private_ip->toBeNull();
});

it('validates the private network and address', function (array $input, string $field) use ($server) {
    $network = Network::factory()->create(['cidr' => '10.104.0.0/20']);
    Server::factory()->create(['network_id' => $network->id, 'private_ip' => '10.104.0.9', 'name' => 'taken']);

    $this->post('/servers', [...$server, ...array_map(fn (string $value): string => str_replace('{network}', (string) $network->id, $value), $input)])
        ->assertSessionHasErrors($field);
})->with([
    'unknown network' => [['network' => '999', 'private_ip' => '10.0.0.1'], 'network'],
    'missing address' => [['network' => '{network}'], 'private_ip'],
    'address outside the range' => [['network' => '{network}', 'private_ip' => '10.200.0.1'], 'private_ip'],
    'address already used' => [['network' => '{network}', 'private_ip' => '10.104.0.9'], 'private_ip'],
    'range with host bits' => [['network' => 'new', 'network_name' => 'x', 'network_cidr' => '10.0.1.5/24', 'private_ip' => '10.0.1.2'], 'network_cidr'],
    'new network without a name' => [['network' => 'new', 'network_cidr' => '10.0.1.0/24', 'private_ip' => '10.0.1.2'], 'network_name'],
]);

it('checks the address on a ready server before saving it', function () {
    $server = Server::factory()->ready()->onNetwork(null)->create(['private_ip' => null]);
    $network = Network::factory()->create(['cidr' => '10.104.0.0/20']);
    $ssh = fakeSsh()->respond("10.104.0.5 is not assigned on this server.\n", 1)->respond("\n");

    $this->put("/servers/{$server->id}/network", ['network' => (string) $network->id, 'private_ip' => '10.104.0.5'])
        ->assertSessionHasErrors(['private_ip' => "10.104.0.5 is not assigned on {$server->name}."]);
    expect($server->fresh()->network_id)->toBeNull();

    $this->put("/servers/{$server->id}/network", ['network' => (string) $network->id, 'private_ip' => '10.104.0.6'])->assertSessionHasNoErrors();
    expect($server->fresh())->network_id->toBe($network->id)->private_ip->toBe('10.104.0.6')
        ->and($ssh->scriptAt(1))->toContain("grep -qxF '10.104.0.6'");
});

it('keeps the network of a server that runs a database', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $server = $database->seed()->server;

    $this->put("/servers/{$server->id}/network", ['network' => ''])
        ->assertSessionHasErrors(['network' => "{$server->name} is part of a database, remove it from the database first."]);
    expect($server->fresh()->network_id)->not->toBeNull();
});

it('adds a server without a private network when the form sends an empty choice', function () use ($server) {
    $this->post('/servers', [...$server, 'network' => '', 'network_name' => '', 'network_cidr' => '', 'private_ip' => ''])
        ->assertSessionHasNoErrors();

    expect(Server::query()->sole())->network_id->toBeNull()->private_ip->toBeNull();
});
