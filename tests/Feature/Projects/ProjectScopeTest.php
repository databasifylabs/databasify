<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use App\Models\User;

test('resources of another project return 404', function (string $method, string $route, Closure $resource) {
    $current = Project::factory()->create();
    $other = Project::factory()->create();
    [$parameter, $model] = $resource($other);

    $this->actingAs(User::factory()->create())
        ->json($method, route($route, ['project' => $current, $parameter => $model]))
        ->assertNotFound();

    $this->assertModelExists($model);
})->with([
    'database' => ['GET', 'databases.show', fn (Project $project) => ['database', Database::factory()->for($project)->create()]],
    'database delete' => ['DELETE', 'databases.destroy', fn (Project $project) => ['database', Database::factory()->for($project)->create()]],
    'server' => ['GET', 'servers.show', fn (Project $project) => ['server', Server::factory()->for($project)->create()]],
    'server delete' => ['DELETE', 'servers.destroy', fn (Project $project) => ['server', Server::factory()->for($project)->create()]],
    'server provider' => ['DELETE', 'providers.destroy', fn (Project $project) => ['provider', ServerProvider::factory()->for($project)->create()]],
    'dns provider' => ['DELETE', 'dns.destroy', fn (Project $project) => ['provider', DnsProvider::factory()->for($project)->create()]],
    'storage provider' => ['GET', 'storage-providers.edit', fn (Project $project) => ['provider', StorageProvider::factory()->for($project)->create()]],
    'network' => ['DELETE', 'networks.destroy', fn (Project $project) => ['network', Network::factory()->for($project)->create()]],
]);

test('project urls must be a uuid', function () {
    $this->actingAs(User::factory()->create())
        ->get('/not-a-uuid/databases')
        ->assertNotFound();
});
