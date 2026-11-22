<?php

declare(strict_types=1);

use App\Jobs\Servers\LaunchProviderServerJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const MCP_BUCKET_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

/**
 * @param  list<string>  $names
 * @return array<string, mixed>
 */
function mcpCloudflareZones(array $names): array
{
    return [
        'result' => array_map(fn (string $name): array => ['id' => "zone-{$name}", 'name' => $name], $names),
        'result_info' => ['total_pages' => 1],
    ];
}

function fakeMcpBucket(): void
{
    Http::fake(['https://s3.example.com/archives/*' => fn (Request $request) => match ($request->method()) {
        'GET' => Http::response('databasify'),
        'DELETE' => Http::response(status: 204),
        default => Http::response(),
    }]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mcpBucketForm(array $overrides = []): array
{
    return [
        'name' => 'backups',
        'endpoint' => 'https://s3.example.com',
        'region' => 'us-east-1',
        'bucket' => 'archives',
        'path_style' => true,
        'access_key_id' => 'AKIDEXAMPLE',
        'secret_access_key' => MCP_BUCKET_SECRET,
        ...$overrides,
    ];
}

describe('server providers', function () {
    test('server providers are listed with their counts and without credentials', function () {
        ServerProvider::factory()->create(['name' => 'do']);
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_server_providers');

        expect(mcpData($response)['data'][0])
            ->toMatchArray(['name' => 'do', 'servers_count' => 0, 'networks_count' => 0])
            ->not->toHaveKey('credentials');
    });

    test('a project lists only its own server providers', function () {
        $mine = ServerProvider::factory()->for(Project::factory())->create();
        ServerProvider::factory()->for(Project::factory())->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_server_providers', ['project' => $mine->project_id]);

        expect(array_column(mcpData($response)['data'], 'id'))->toBe([$mine->id]);
    });

    test('server provider types list the installed providers', function () {
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_server_provider_types');

        expect(array_column(mcpData($response), 'value'))->toContain('digitalocean');
    });

    test('a server provider is shown with its counts', function () {
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('get_server_provider', ['provider' => $provider->id]);

        expect(mcpData($response))->toMatchArray(['id' => $provider->id, 'servers_count' => 0, 'networks_count' => 0]);
    });

    test('a server provider is added to a project after its token is checked', function () {
        Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['account' => ['status' => 'active']])]);
        $project = Project::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('create_server_provider', ['project' => $project->id, 'name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'dop_v1_valid']]);

        expect(mcpData($response))->toMatchArray(['project_id' => $project->id, 'name' => 'do'])->not->toHaveKey('credentials');
        $this->assertDatabaseHas('server_providers', ['project_id' => $project->id, 'name' => 'do', 'type' => 'digitalocean']);
    });

    test('a server provider in another project is not found for a limited key', function () {
        $provider = ServerProvider::factory()->create(['name' => 'do']);
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->limitedTo(Project::factory()->create())->create());

        $response = mcpCall('update_server_provider', ['provider' => $provider->id, 'name' => 'renamed']);

        expect(mcpError($response))->toContain('No server_provider with this id.');
        $this->assertDatabaseHas('server_providers', ['id' => $provider->id, 'name' => 'do']);
    });

    test('a server provider is renamed', function () {
        $provider = ServerProvider::factory()->create(['name' => 'do']);
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('update_server_provider', ['provider' => $provider->id, 'name' => 'digitalocean']);

        expect(mcpData($response)['name'])->toBe('digitalocean');
        $this->assertDatabaseHas('server_providers', ['id' => $provider->id, 'name' => 'digitalocean']);
    });

    test('a server provider with servers is not deleted', function () {
        $provider = ServerProvider::factory()->create(['name' => 'do']);
        Server::factory()->for($provider->project)->create(['server_provider_id' => $provider->id]);
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('delete_server_provider', ['provider' => $provider->id]);

        expect(mcpError($response))->toContain('do still has')->toContain('Delete them first.');
        $this->assertModelExists($provider);
    });

    test('an unused server provider is deleted', function () {
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('delete_server_provider', ['provider' => $provider->id]);

        expect(mcpData($response))->toBe(['deleted' => $provider->id]);
        $this->assertModelMissing($provider);
    });

    test('regions are read from the provider api', function () {
        Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'ams3', 'name' => 'Amsterdam 3', 'available' => true]]])]);
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_provider_regions', ['provider' => $provider->id]);

        expect(mcpData($response))->toBe([['id' => 'ams3', 'name' => 'Amsterdam 3', 'country' => 'NL']]);
    });

    test('plans of a region are read from the provider api', function () {
        Http::fake(['https://api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [
            ['slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 80, 'price_monthly' => 24, 'available' => true, 'regions' => ['ams3'], 'description' => 'Basic'],
        ]])]);
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_provider_plans', ['provider' => $provider->id, 'region' => 'ams3']);

        expect(array_column(mcpData($response), 'id'))->toBe(['s-2vcpu-4gb']);
    });

    test('networks of a region are read from the provider api', function () {
        Http::fake(['https://api.digitalocean.com/v2/vpcs*' => Http::response(['vpcs' => [['id' => 'vpc-1', 'name' => 'default-ams3', 'region' => 'ams3', 'ip_range' => '10.110.0.0/20']]])]);
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_provider_networks', ['provider' => $provider->id, 'region' => 'ams3']);

        expect(json_encode(mcpData($response)))->toContain('vpc-1');
    });

    test('networks are listed with their cidr and server count', function () {
        $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

        $response = mcpCall('list_networks', ['project' => $network->project_id]);

        expect(mcpData($response)['data'][0])->toMatchArray(['id' => $network->id, 'cidr' => '10.10.0.0/16', 'servers_count' => 0]);
    });

    test('an empty network is deleted', function () {
        $network = Network::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('delete_network', ['network' => $network->id]);

        expect(mcpData($response))->toBe(['deleted' => $network->id]);
        $this->assertModelMissing($network);
    });

    test('ordering a server queues its launch', function () {
        Queue::fake([LaunchProviderServerJob::class]);
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('order_server', ['provider' => $provider->id, 'name' => 'db-1', 'region' => 'nyc3', 'plan' => 's-2vcpu-4gb', 'os' => 'ubuntu-24.04']);

        expect(mcpData($response))->toMatchArray(['name' => 'db-1', 'status' => 'creating', 'server_provider_id' => $provider->id, 'project_id' => $provider->project_id]);
        $this->assertDatabaseHas('servers', ['name' => 'db-1', 'server_provider_id' => $provider->id]);
        Queue::assertPushed(LaunchProviderServerJob::class);
    });

    test('ordering with an operating system the provider does not offer is refused', function () {
        Queue::fake([LaunchProviderServerJob::class]);
        $provider = ServerProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

        $response = mcpCall('order_server', ['provider' => $provider->id, 'name' => 'db-1', 'region' => 'nyc3', 'plan' => 's-2vcpu-4gb', 'os' => 'debian-12']);

        expect(mcpError($response))->toContain('DigitalOcean does not offer Debian 12.');
        $this->assertDatabaseMissing('servers', ['name' => 'db-1']);
        Queue::assertNotPushed(LaunchProviderServerJob::class);
    });
});

describe('dns providers', function () {
    test('dns providers are listed with their zones and without credentials', function () {
        $provider = DnsProvider::factory()->create(['name' => 'cf']);
        DnsZone::factory()->for($provider, 'provider')->create(['name' => 'example.com']);
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->create());

        $response = mcpCall('list_dns_providers', ['project' => $provider->project_id]);

        $listed = mcpData($response)['data'][0];
        expect($listed)->not->toHaveKey('credentials')
            ->and($listed['zones'][0]['name'])->toBe('example.com');
    });

    test('dns provider types list the installed providers', function () {
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->create());

        $response = mcpCall('list_dns_provider_types');

        expect(array_column(mcpData($response), 'value'))->toContain('cloudflare');
    });

    test('a dns provider is added with its zones', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response(mcpCloudflareZones(['example.com'])),
        ]);
        $project = Project::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

        $response = mcpCall('create_dns_provider', ['project' => $project->id, 'name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-valid']]);

        expect(mcpData($response)['zones'][0]['name'])->toBe('example.com');
        $this->assertDatabaseHas('dns_providers', ['project_id' => $project->id, 'name' => 'cf']);
        $this->assertDatabaseHas('dns_zones', ['name' => 'example.com']);
    });

    test('a dns provider in another project is not found for a limited key', function () {
        $provider = DnsProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->limitedTo(Project::factory()->create())->create());

        $response = mcpCall('get_dns_provider', ['provider' => $provider->id]);

        expect(mcpError($response))->toContain('No dns_provider with this id.');
    });

    test('a dns provider is renamed', function () {
        $provider = DnsProvider::factory()->create(['name' => 'cf']);
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

        $response = mcpCall('update_dns_provider', ['provider' => $provider->id, 'name' => 'cloudflare']);

        expect(mcpData($response)['name'])->toBe('cloudflare');
        $this->assertDatabaseHas('dns_providers', ['id' => $provider->id, 'name' => 'cloudflare']);
    });

    test('refreshing a dns provider imports its new zones', function () {
        Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response(mcpCloudflareZones(['example.com', 'new.dev']))]);
        $provider = DnsProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

        $response = mcpCall('refresh_dns_provider', ['provider' => $provider->id]);

        expect(array_column(mcpData($response)['zones'], 'name'))->toEqualCanonicalizing(['example.com', 'new.dev']);
        $this->assertDatabaseHas('dns_zones', ['dns_provider_id' => $provider->id, 'name' => 'new.dev']);
    });

    test('a dns provider whose domain a database uses is not deleted', function () {
        $provider = DnsProvider::factory()->create(['name' => 'cf']);
        $zone = DnsZone::factory()->for($provider, 'provider')->create();
        Database::factory()->for($provider->project)->create(['name' => 'orders', 'dns_zone_id' => $zone->id]);
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

        $response = mcpCall('delete_dns_provider', ['provider' => $provider->id]);

        expect(mcpError($response))->toContain('orders uses a domain from cf, pick another domain for it first.');
        $this->assertModelExists($provider);
    });

    test('an unused dns provider is deleted', function () {
        $provider = DnsProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

        $response = mcpCall('delete_dns_provider', ['provider' => $provider->id]);

        expect(mcpData($response))->toBe(['deleted' => $provider->id]);
        $this->assertModelMissing($provider);
    });
});

describe('storage providers', function () {
    test('a storage provider is added and its secret is never returned', function () {
        fakeMcpBucket();
        $project = Project::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

        $response = mcpCall('create_storage_provider', ['project' => $project->id, ...mcpBucketForm()]);

        expect(mcpData($response))->toMatchArray(['name' => 'backups', 'access_key_id' => 'AKIDEXAMPLE'])->not->toHaveKey('secret_access_key')
            ->and(json_encode($response))->not->toContain('EXAMPLEKEY');
        $this->assertDatabaseHas('storage_providers', ['project_id' => $project->id, 'name' => 'backups', 'bucket' => 'archives']);
    });

    test('a project lists only its own storage providers', function () {
        $provider = StorageProvider::factory()->for(Project::factory())->create();
        StorageProvider::factory()->for(Project::factory())->create();
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:read')->create());

        $response = mcpCall('list_storage_providers', ['project' => $provider->project_id]);

        expect(array_column(mcpData($response)['data'], 'id'))->toBe([$provider->id]);
    });

    test('a storage provider is shown with the databases that back up to it', function () {
        $provider = StorageProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:read')->create());

        $response = mcpCall('get_storage_provider', ['provider' => $provider->id]);

        expect(mcpData($response))->toMatchArray(['id' => $provider->id, 'database_ids' => []]);
    });

    test('a storage provider is updated and its secret is never returned', function () {
        fakeMcpBucket();
        $provider = StorageProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

        $response = mcpCall('update_storage_provider', ['provider' => $provider->id, ...mcpBucketForm(['name' => 'renamed'])]);

        expect(mcpData($response)['name'])->toBe('renamed')
            ->and(json_encode($response))->not->toContain('EXAMPLEKEY');
        $this->assertDatabaseHas('storage_providers', ['id' => $provider->id, 'name' => 'renamed']);
    });

    test('testing a storage provider marks it verified', function () {
        fakeMcpBucket();
        $provider = StorageProvider::factory()->create(['verified_at' => null]);
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

        $response = mcpCall('test_storage_provider', ['provider' => $provider->id]);

        expect(mcpData($response)['verified_at'])->not->toBeNull()
            ->and($provider->refresh()->verified_at)->not->toBeNull();
    });

    test('an unused storage provider is deleted', function () {
        $provider = StorageProvider::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

        $response = mcpCall('delete_storage_provider', ['provider' => $provider->id]);

        expect(mcpData($response))->toBe(['deleted' => $provider->id]);
        $this->assertModelMissing($provider);
    });
});
