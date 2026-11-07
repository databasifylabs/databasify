<?php

declare(strict_types=1);

use App\Jobs\Servers\CreateServerUserJob;
use App\Jobs\Servers\DeleteServerUserJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([ProvisionServerJob::class, CreateServerUserJob::class, DeleteServerUserJob::class, UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
});

it('creates an isolated user with a validated key', function () {
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/servers/{$server->id}/users"), ['username' => 'app', 'public_key' => "ssh-ed25519 AAAA' ; rm -rf /"])
        ->assertSessionHasErrors('public_key');
    $this->post(projectUrl("/servers/{$server->id}/users"), ['username' => 'app'])->assertRedirect();

    expect($server->users()->pluck('username')->all())->toBe(['app']);
    Queue::assertPushed(CreateServerUserJob::class, fn (CreateServerUserJob $job) => $job->server->is($server) && $job->username === 'app' && $job->publicKey === null);
});

it('rejects reserved and unsafe usernames', function (string $username) {
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/servers/{$server->id}/users"), ['username' => $username])->assertSessionHasErrors('username');

    expect($server->users()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['root', 'databasify-engine', 'App', 'app;id', '']);

it('refuses to create a user on a server that is not ready', function () {
    $server = Server::factory()->create();

    $this->post(projectUrl("/servers/{$server->id}/users"), ['username' => 'app'])->assertSessionHasErrors('server');

    Queue::assertNothingPushed();
});

it('deletes a user of the server', function () {
    $server = Server::factory()->ready()->create();
    $user = $server->users()->create(['username' => 'app']);

    $this->delete(projectUrl("/servers/{$server->id}/users/{$user->id}"))->assertRedirect();

    expect($server->users()->count())->toBe(0);
    Queue::assertPushed(DeleteServerUserJob::class, fn (DeleteServerUserJob $job) => $job->server->is($server) && $job->username === 'app');
});

it('returns not found for a user of another server', function () {
    $server = Server::factory()->ready()->create();
    $user = Server::factory()->ready()->create()->users()->create(['username' => 'app']);

    $this->delete(projectUrl("/servers/{$server->id}/users/{$user->id}"))->assertNotFound();

    expect($user->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});
