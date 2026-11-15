<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;

test('a server user can be created', function () {
    $server = Server::factory()->ready()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.users.store', [$server->project_id, $server]), ['username' => 'deploy', 'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBk deploy@laptop'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('server_users', ['server_id' => $server->id, 'username' => 'deploy']);
    expect($this->ssh()->ran('useradd --create-home --shell /bin/bash deploy', $server))->toBeTrue();
});

test('reserved server user names are refused', function (string $username) {
    $server = Server::factory()->ready()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.users.store', [$server->project_id, $server]), ['username' => $username])
        ->assertSessionHasErrors(['username' => 'The selected username is invalid.']);

    expect($this->ssh()->ranNothing())->toBeTrue();
})->with(['root', 'databasify-engine']);

test('server users can only be created on a ready server', function () {
    $server = Server::factory()->create(['name' => 'db-1']);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.users.store', [$server->project_id, $server]), ['username' => 'deploy'])
        ->assertSessionHasErrors(['server' => 'db-1 is not ready yet.']);

    $this->assertDatabaseMissing('server_users', ['username' => 'deploy']);
});

test('a failing user script is shown and nothing is recorded', function () {
    $server = Server::factory()->ready()->create();
    $this->ssh()->replyTo('useradd', "useradd: failure\n", exit: 1);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.users.store', [$server->project_id, $server]), ['username' => 'deploy'])
        ->assertSessionHasErrors('server');

    $this->assertDatabaseMissing('server_users', ['username' => 'deploy']);
});

test('a server user can be deleted', function () {
    $server = Server::factory()->ready()->create();
    $user = $server->users()->create(['username' => 'deploy']);

    $this->actingAs(User::factory()->create())
        ->delete(route('servers.users.destroy', [$server->project_id, $server, $user]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($user);
    expect($this->ssh()->ran('deploy', $server))->toBeTrue();
});

test('a user of another server cannot be deleted through this one', function () {
    $server = Server::factory()->ready()->create();
    $other = Server::factory()->ready()->create();
    $user = $other->users()->create(['username' => 'deploy']);

    $this->actingAs(User::factory()->create())
        ->delete(route('servers.users.destroy', [$server->project_id, $server, $user]))
        ->assertNotFound();

    $this->assertModelExists($user);
});
