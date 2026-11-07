<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    ProjectFactory::defaultProject();
    $this->other = Project::factory()->create();
});

it('returns 404 for a provider of another project', function () {
    $provider = ServerProvider::factory()->for($this->other)->create();

    $this->delete(projectUrl("/providers/{$provider->id}"))->assertNotFound();

    expect($provider->fresh())->not->toBeNull();
});

it('allows the same provider name once per project', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'x']])]);
    ServerProvider::factory()->for($this->other)->create(['name' => 'do']);
    $input = ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'good']];

    $this->post(projectUrl('/providers'), $input)->assertSessionHasNoErrors();
    $this->post(projectUrl('/providers'), $input)->assertSessionHasErrors(['name' => 'The name has already been taken.']);

    expect(ServerProvider::query()->withoutProject()->where('name', 'do')->count())->toBe(2);
});

it('refuses to delete a provider whose servers moved to another project', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Server::factory()->for($this->other)->create(['server_provider_id' => $provider->id]);

    $this->delete(projectUrl("/providers/{$provider->id}"))->assertSessionHasErrors(['provider' => 'do still has 1 server in Databasify. Delete them first.']);
});

it('refuses a provider of another project when ordering a server', function () {
    $provider = ServerProvider::factory()->for($this->other)->create();

    $this->post(projectUrl('/providers/servers'), ['server_provider_id' => $provider->id, 'name' => 'db-1', 'region' => 'blr1', 'plan' => 's-1vcpu-1gb', 'os' => 'ubuntu-24.04'])
        ->assertSessionHasErrors(['server_provider_id' => 'The selected server provider id is invalid.']);
});
