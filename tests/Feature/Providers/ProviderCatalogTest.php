<?php

declare(strict_types=1);

use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('provider regions list only available regions by name', function () {
    Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [
        ['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true],
        ['slug' => 'ams3', 'name' => 'Amsterdam 3', 'available' => true],
        ['slug' => 'sfo1', 'name' => 'San Francisco 1', 'available' => false],
    ]])]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('providers.regions', [$provider->project_id, $provider]))
        ->assertOk()
        ->assertExactJson([
            ['id' => 'ams3', 'name' => 'Amsterdam 3', 'country' => 'NL'],
            ['id' => 'nyc3', 'name' => 'New York 3', 'country' => 'US'],
        ]);
});

test('provider plans skip sizes too small or not offered in the region', function () {
    Http::fake(['https://api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [
        ['slug' => 's-2vcpu-4gb', 'description' => 'Basic', 'vcpus' => 2, 'memory' => 4096, 'disk' => 80, 'price_monthly' => 24, 'available' => true, 'regions' => ['nyc3']],
        ['slug' => 's-1vcpu-512mb', 'description' => 'Basic', 'vcpus' => 1, 'memory' => 512, 'disk' => 10, 'price_monthly' => 4, 'available' => true, 'regions' => ['nyc3']],
        ['slug' => 's-4vcpu-8gb', 'description' => 'Basic', 'vcpus' => 4, 'memory' => 8192, 'disk' => 160, 'price_monthly' => 48, 'available' => true, 'regions' => ['ams3']],
    ]])]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('providers.plans', [$provider->project_id, $provider, 'nyc3']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', 's-2vcpu-4gb');
});

test('provider networks list the private networks in the region', function () {
    Http::fake(['https://api.digitalocean.com/v2/vpcs*' => Http::response(['vpcs' => [
        ['id' => 'vpc-1', 'name' => 'default-nyc3', 'ip_range' => '10.116.0.0/20', 'region' => 'nyc3', 'default' => true],
        ['id' => 'vpc-2', 'name' => 'default-ams3', 'ip_range' => '10.110.0.0/20', 'region' => 'ams3', 'default' => true],
    ]])]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('providers.networks', [$provider->project_id, $provider, 'nyc3']))
        ->assertOk()
        ->assertExactJson([['external_id' => 'vpc-1', 'name' => 'default-nyc3', 'cidr' => '10.116.0.0/20', 'region' => 'nyc3', 'is_default' => true]]);
});
