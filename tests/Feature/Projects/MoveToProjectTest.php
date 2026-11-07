<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\DnsProvider;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    ProjectFactory::defaultProject();
    $this->target = Project::factory()->create(['name' => 'Staging']);
});

it('moves a database together with its servers', function () {
    $database = idleDatabase('valkey', 2, DatabaseMode::HighAvailability, 'cache');

    $this->post(projectUrl('/moves'), ['type' => 'database', 'id' => $database->id, 'project_id' => $this->target->id])
        ->assertRedirect("/{$this->target->id}/databases/{$database->id}")
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved to Staging.']);

    expect($database->fresh()?->project_id)->toBe($this->target->id)
        ->and(Server::query()->pluck('project_id')->unique()->all())->toBe([$this->target->id]);
});

it('refuses to move a server that belongs to a database', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $server = $database->seed()->server;

    $this->post(projectUrl('/moves'), ['type' => 'server', 'id' => $server->id, 'project_id' => $this->target->id])
        ->assertSessionHasErrors(['id' => 'Move database cache instead; its servers move with it.']);

    expect($server->fresh()?->project_id)->not->toBe($this->target->id);
});

it('moves a provider while its servers stay and keep their provider', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    $server = Server::factory()->create(['server_provider_id' => $provider->id]);

    $this->post(projectUrl('/moves'), ['type' => 'server-provider', 'id' => $provider->id, 'project_id' => $this->target->id])
        ->assertRedirect("/{$this->target->id}/providers");

    expect($provider->fresh()?->project_id)->toBe($this->target->id)
        ->and($server->fresh()?->project_id)->not->toBe($this->target->id);
    $this->get(projectUrl("/servers/{$server->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('server.data.provider.name', 'do'));
});

it('moves networks, DNS and storage providers alone', function (string $type, Closure $create) {
    $resource = $create();

    $this->post(projectUrl('/moves'), ['type' => $type, 'id' => $resource->id, 'project_id' => $this->target->id])->assertSessionHasNoErrors();

    expect($resource->fresh()?->project_id)->toBe($this->target->id);
})->with([
    'network' => ['network', fn () => Network::factory()->create()],
    'dns provider' => ['dns-provider', fn () => DnsProvider::factory()->create()],
    'storage provider' => ['storage-provider', fn () => StorageProvider::factory()->create()],
]);

it('refuses the current project as the target', function () {
    $server = Server::factory()->create();

    $this->post(projectUrl('/moves'), ['type' => 'server', 'id' => $server->id, 'project_id' => $server->project_id])
        ->assertSessionHasErrors(['project_id' => 'Pick a different project.']);
});

it('refuses a resource from another project', function () {
    $server = Server::factory()->for($this->target)->create();

    $this->post(projectUrl('/moves'), ['type' => 'server', 'id' => $server->id, 'project_id' => $this->target->id])
        ->assertSessionHasErrors(['id' => 'The selected id is invalid.']);
});
