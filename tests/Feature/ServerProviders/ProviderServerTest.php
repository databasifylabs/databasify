<?php

declare(strict_types=1);

use App\Actions\Providers\LaunchProviderServer;
use App\Contracts\Ssh\PortProbe;
use App\Enums\Servers\OperatingSystem;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Jobs\Servers\LaunchProviderServerJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const DIGITALOCEAN = 'https://api.digitalocean.com/v2';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function sshAnswersAfter(int $closedPolls): void
{
    app()->instance(PortProbe::class, new class($closedPolls) implements PortProbe
    {
        public function __construct(private int $closedPolls) {}

        public function isOpen(string $host, int $port): bool
        {
            return $this->closedPolls-- <= 0;
        }
    });
}

it('orders a droplet in the chosen vpc and records that network', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();
    Http::fake([DIGITALOCEAN.'/vpcs*' => Http::response(['vpcs' => [['id' => 'vpc-1', 'name' => 'default-blr1', 'region' => 'blr1', 'ip_range' => '10.104.0.0/20', 'default' => true]]])]);

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'vpc-1'])
        ->assertRedirect();

    $server = Server::query()->where('name', 'db-04')->sole();
    expect($server)
        ->status->toBe(ServerStatus::Creating)
        ->public_ip->toBeNull()
        ->ssh_user->toBe('root')
        ->region->toBe('blr1')
        ->image->toBe(OperatingSystem::Ubuntu2604)
        ->network->external_id->toBe('vpc-1')
        ->network->cidr->toBe('10.104.0.0/20');
    Queue::assertPushed(LaunchProviderServerJob::class, fn (LaunchProviderServerJob $job): bool => $job->server->is($server));
});

it('creates a new vpc for the server when asked', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();
    Http::fake([DIGITALOCEAN.'/vpcs' => Http::response(['vpc' => ['id' => 'vpc-new', 'name' => 'databasify-blr1-x', 'region' => 'blr1', 'ip_range' => '10.122.16.0/20', 'default' => false]], 201)]);

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'new'])
        ->assertSessionHasNoErrors();

    expect(Server::query()->where('name', 'db-04')->sole()->network)
        ->external_id->toBe('vpc-new')->cidr->toBe('10.122.16.0/20')->server_provider_id->toBe($provider->id);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_starts_with((string) $request['name'], 'databasify-blr1-'));
});

it('keeps no server when the provider refuses to create the network', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake([DIGITALOCEAN.'/vpcs' => Http::response(['message' => 'quota reached'], 422)]);

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'new'])
        ->assertSessionHasErrors(['network' => 'DigitalOcean could not create a private network: DigitalOcean answered 422. quota reached']);
    expect(Server::query()->where('name', 'db-04')->exists())->toBeFalse();
});

it('refuses a vpc the region does not have', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake([DIGITALOCEAN.'/vpcs*' => Http::response(['vpcs' => []])]);

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'vpc-9'])
        ->assertSessionHasErrors(['network' => 'That private network is not in blr1 anymore.']);
    expect(Server::query()->where('name', 'db-04')->exists())->toBeFalse();
});

function orderedServer(?Network $network = null): Server
{
    $provider = ServerProvider::factory()->create();

    return Server::factory()->create([
        'name' => 'db-04', 'public_ip' => null, 'private_ip' => null, 'network_id' => $network?->id,
        'server_provider_id' => $provider->id, 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'image' => OperatingSystem::Ubuntu2604, 'status' => ServerStatus::Creating,
    ]);
}

it('launches the droplet, waits for its addresses and ssh, then queues setup', function () {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(2);
    $server = orderedServer(Network::factory()->create(['server_provider_id' => null, 'external_id' => 'vpc-1', 'cidr' => '10.104.0.0/20']));
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::sequence()
            ->push(['droplet' => ['status' => 'new', 'networks' => ['v4' => []]]])
            ->push(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public'], ['ip_address' => '10.104.0.12', 'type' => 'private']]]]]),
    ]);

    resolve(LaunchProviderServer::class)->handle($server);

    expect($server->fresh())
        ->provider_server_id->toBe('9001')
        ->public_ip->toBe('203.0.113.20')
        ->private_ip->toBe('10.104.0.12')
        ->status->toBe(ServerStatus::Pending);
    Http::assertSent(fn (Request $request): bool => $request->url() === DIGITALOCEAN.'/droplets' && $request['vpc_uuid'] === 'vpc-1' && $request['ssh_keys'] === ['42'] && $request['image'] === 'ubuntu-26-04-x64');
    Sleep::assertSleptTimes(3);
    Queue::assertPushed(ProvisionServerJob::class, fn (ProvisionServerJob $job): bool => $job->server->is($server) && $job->disablePasswordLogin);
    expect($server->serverLogs()->pluck('status')->all())->each->toBe(ServerLogStatus::Succeeded)
        ->and($server->serverLogs()->pluck('output', 'name')->all())->toEqual([
            'Order server from DigitalOcean' => "Ordering db-04: plan s-1vcpu-2gb in blr1, image ubuntu-26-04-x64\nDigitalOcean created instance 9001\n",
            'Wait for DigitalOcean to start the server' => "Check 1/60: starting, no IP address yet\nCheck 2/60: running, public IP 203.0.113.20, private IP 10.104.0.12\n",
            'Wait for SSH' => "Check 1/60: SSH on 203.0.113.20:22 is not answering yet\nCheck 2/60: SSH on 203.0.113.20:22 is not answering yet\nSSH answers on 203.0.113.20:22\n",
        ]);
});

it('logs the provider error when ordering the server fails', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = orderedServer();
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['message' => 'size is unavailable'], 422),
    ]);

    expect(fn () => LaunchProviderServerJob::dispatchSync($server))->toThrow(ProviderRequestFailed::class);
    expect($server->serverLogs()->sole())
        ->name->toBe('Order server from DigitalOcean')
        ->status->toBe(ServerLogStatus::Failed)
        ->output->toContain('size is unavailable');
    expect($server->fresh()->status)->toBe(ServerStatus::Failed);
});

it('fails the running launch log when the launch job dies', function () {
    $server = orderedServer();
    $waiting = ServerLog::factory()->for($server)->create(['name' => 'Wait for SSH', 'status' => ServerLogStatus::Running, 'output' => '']);
    $other = ServerLog::factory()->for($server)->create(['name' => 'Update packages', 'status' => ServerLogStatus::Running, 'output' => '']);

    (new LaunchProviderServerJob($server))->failed(new RuntimeException('Job timed out'));

    expect($waiting->fresh())->status->toBe(ServerLogStatus::Failed)->output->toContain('Job timed out')
        ->and($other->fresh()->status)->toBe(ServerLogStatus::Running);
});

it('fails the server when the droplet never starts', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = orderedServer();
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::response(['droplet' => ['status' => 'new', 'networks' => ['v4' => []]]]),
    ]);

    expect(fn () => LaunchProviderServerJob::dispatchSync($server))->toThrow(RuntimeException::class, 'It did not start within 10 minutes.');
    expect($server->fresh()->status)->toBe(ServerStatus::Failed);
    expect($server->serverLogs()->where('name', 'Wait for DigitalOcean to start the server')->sole())
        ->status->toBe(ServerLogStatus::Failed)
        ->output->toContain('Check 1/60: starting, no IP address yet')->toContain('It did not start within 10 minutes.');
    Queue::assertNothingPushed();
});

it('keeps polling through one failed poll', function () {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(0);
    $server = orderedServer();
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::sequence()
            ->push('', 503)
            ->push('', 503)
            ->push(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public']]]]]),
    ]);

    resolve(LaunchProviderServer::class)->handle($server);

    expect($server->fresh())->status->toBe(ServerStatus::Pending);
});

it('returns as soon as ssh answers', function () {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(0);
    $server = orderedServer();
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::response(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public']]]]]),
    ]);

    resolve(LaunchProviderServer::class)->handle($server);

    Sleep::assertNeverSlept();
});

it('fails the server when ssh never answers', function () {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(PHP_INT_MAX);
    $server = orderedServer();
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::response(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public']]]]]),
    ]);

    expect(fn () => resolve(LaunchProviderServer::class)->handle($server))
        ->toThrow(RuntimeException::class, 'SSH did not answer within 10 minutes.');
    expect($server->fresh()->status)->toBe(ServerStatus::Failed);
    Queue::assertNothingPushed();
});

it('destroys the droplet only when asked', function () {
    $server = orderedServer();
    $server->update(['status' => ServerStatus::Ready, 'provider_server_id' => '9001', 'public_ip' => '203.0.113.20']);
    $kept = Server::factory()->ready()->create(['server_provider_id' => $server->server_provider_id, 'provider_server_id' => '9002']);
    Http::fake([DIGITALOCEAN.'/droplets/*' => Http::response(null, 204)]);

    $this->delete("/servers/{$kept->id}")->assertRedirect('/servers');
    $this->delete("/servers/{$server->id}", ['destroy_at_provider' => true])->assertRedirect('/servers');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === DIGITALOCEAN.'/droplets/9001');
    expect(Server::query()->count())->toBe(0);
});

it('keeps the server when the provider refuses to destroy it', function () {
    $server = orderedServer();
    $server->update(['status' => ServerStatus::Ready, 'provider_server_id' => '9001', 'public_ip' => '203.0.113.20']);
    Http::fake([DIGITALOCEAN.'/droplets/9001' => Http::response(['message' => 'locked'], 422)]);

    $this->delete("/servers/{$server->id}", ['destroy_at_provider' => true])
        ->assertSessionHasErrors(['server' => 'Could not destroy db-04 at DigitalOcean: DigitalOcean answered 422. locked']);
    expect($server->fresh())->not->toBeNull();
});

it('offers only the supported releases, each on the providers that have its image', function () {
    $provider = ServerProvider::factory()->create();

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-22.04'])
        ->assertSessionHasErrors('os');
    $this->get('/servers/create')->assertInertia(fn ($page) => $page
        ->where('operatingSystems.0', [
            'value' => 'ubuntu-24.04', 'label' => 'Ubuntu 24.04 LTS', 'version' => '24.04', 'distribution' => 'ubuntu', 'family' => 'debian',
            'recommended' => true, 'icon' => asset('assets/icons/os/ubuntu.svg'), 'providers' => ['digitalocean', 'hetzner', 'vultr', 'linode'],
        ])
        ->where('operatingSystems.2', [
            'value' => 'debian-12', 'label' => 'Debian 12', 'version' => '12', 'distribution' => 'debian', 'family' => 'debian',
            'recommended' => false, 'icon' => asset('assets/icons/os/debian.svg'), 'providers' => ['hetzner', 'vultr', 'linode'],
        ])
        ->where('operatingSystems.4.family', 'rhel')
        ->where('operatingSystems.5.label', 'AlmaLinux 9'));
});

it('refuses an operating system the provider has no image of', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'debian-12'])
        ->assertSessionHasErrors(['os' => 'DigitalOcean does not offer Debian 12.']);
    expect(Server::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('orders the provider image of the chosen operating system', function (OperatingSystem $system, string $image) {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(0);
    $server = orderedServer();
    $server->update(['image' => $system]);
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9002]], 202),
        DIGITALOCEAN.'/droplets/9002' => Http::response(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.21', 'type' => 'public']]]]]),
    ]);

    resolve(LaunchProviderServer::class)->handle($server);

    Http::assertSent(fn (Request $request): bool => $request->url() === DIGITALOCEAN.'/droplets' && $request['image'] === $image);
})->with([
    'ubuntu 24.04' => [OperatingSystem::Ubuntu2404, 'ubuntu-24-04-x64'],
    'debian 13' => [OperatingSystem::Debian13, 'debian-13-x64'],
    'rocky 9' => [OperatingSystem::Rocky9, 'rockylinux-9-x64'],
    'almalinux 9' => [OperatingSystem::Alma9, 'almalinux-9-x64'],
]);

it('orders a droplet on a databasify network outside any vpc', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();
    $existing = Network::factory()->wireguard()->create();
    Server::factory()->onNetwork($existing)->create(['private_ip' => '100.64.0.1']);

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => "databasify:{$existing->id}"])
        ->assertSessionHasNoErrors();
    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-05', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'databasify:new'])
        ->assertSessionHasNoErrors();

    expect(Server::query()->where('name', 'db-04')->sole())->network_id->toBe($existing->id)->private_ip->toBe('100.64.0.2')
        ->and(Server::query()->where('name', 'db-05')->sole())->private_ip->toBe('100.64.1.1')->network->cidr->toBe('100.64.1.0/24');
    Http::assertNothingSent();
});

it('refuses a databasify network that does not exist', function () {
    $provider = ServerProvider::factory()->create();

    $this->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'databasify:999'])
        ->assertSessionHasErrors('network');
});

it('keeps the databasify address and asks for no vpc when launching', function () {
    Queue::fake([ProvisionServerJob::class]);
    sshAnswersAfter(0);
    $server = orderedServer(Network::factory()->wireguard()->create());
    $server->update(['private_ip' => '100.64.0.1']);
    Http::fake([
        DIGITALOCEAN.'/account/keys/*' => Http::response(['ssh_key' => ['id' => 42]]),
        DIGITALOCEAN.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DIGITALOCEAN.'/droplets/9001' => Http::response(['droplet' => ['status' => 'active', 'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public'], ['ip_address' => '10.104.0.12', 'type' => 'private']]]]]),
    ]);

    resolve(LaunchProviderServer::class)->handle($server);

    expect($server->fresh())->public_ip->toBe('203.0.113.20')->private_ip->toBe('100.64.0.1');
    Http::assertSent(fn (Request $request): bool => $request->url() === DIGITALOCEAN.'/droplets' && ! isset($request['vpc_uuid']));
});

it('sends an inertia form post back with the provider error when a provider call fails', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake([DIGITALOCEAN.'/*' => Http::response(['message' => 'down'], 503)]);

    $this->withHeader('X-Inertia', 'true')
        ->from('/servers/create')
        ->post('/providers/servers', ['server_provider_id' => $provider->id, 'name' => 'db-04', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'vpc-1'])
        ->assertRedirect('/servers/create')
        ->assertSessionHasErrors(['provider' => 'DigitalOcean answered 503. down']);
});
