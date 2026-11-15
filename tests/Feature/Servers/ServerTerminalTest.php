<?php

declare(strict_types=1);

use App\Jobs\Servers\RunTerminalSessionJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\TerminalSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

test('a terminal can be opened on a ready server', function () {
    Queue::fake([RunTerminalSessionJob::class]);
    $server = Server::factory()->ready()->create();

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('servers.terminals.store', [$server->project_id, $server]), ['columns' => 120, 'rows' => 40])
        ->assertCreated();

    expect($response->json('id'))->toBeUuid();
    Queue::assertPushed(RunTerminalSessionJob::class, fn (RunTerminalSessionJob $job) => $job->session->id === $response->json('id'));
});

test('a terminal cannot be opened before the server is ready', function () {
    Queue::fake([RunTerminalSessionJob::class]);
    $server = Server::factory()->create(['name' => 'db-1']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('servers.terminals.store', [$server->project_id, $server]), ['columns' => 120, 'rows' => 40])
        ->assertJsonValidationErrors(['server' => 'db-1 is not ready for a terminal yet.']);

    Queue::assertNotPushed(RunTerminalSessionJob::class);
});

test('input reaches the open terminal', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $user);

    $this->actingAs($user)
        ->postJson(route('servers.terminals.input', [$server->project_id, $server, $session->id]), ['data' => base64_encode("ls\n")])
        ->assertNoContent();

    expect($session->nextInput())->toBe("ls\n");
});

test('input reaches the terminal when the cache returns its activity time as a string', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $user);
    Cache::put("terminals.{$session->id}.active", (string) now()->getTimestamp());

    $this->actingAs($user)
        ->postJson(route('servers.terminals.input', [$server->project_id, $server, $session->id]), ['data' => base64_encode("ls\n")])
        ->assertNoContent();

    expect($session->nextInput())->toBe("ls\n");
});

test('input to a closed terminal returns 410', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $user);
    $session->close();

    $this->actingAs($user)
        ->postJson(route('servers.terminals.input', [$server->project_id, $server, $session->id]), ['data' => base64_encode("ls\n")])
        ->assertGone();
});

test('another user cannot type into or close a terminal', function () {
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, User::factory()->create());
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson(route('servers.terminals.input', [$server->project_id, $server, $session->id]), ['data' => base64_encode("rm -rf /\n")])
        ->assertNotFound();
    $this->actingAs($intruder)
        ->deleteJson(route('servers.terminals.destroy', [$server->project_id, $server, $session->id]))
        ->assertNotFound();

    expect($session->isActive())->toBeTrue()
        ->and($session->nextInput())->toBe('');
});

test('a terminal can be closed by its owner', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $user);

    $this->actingAs($user)
        ->deleteJson(route('servers.terminals.destroy', [$server->project_id, $server, $session->id]))
        ->assertNoContent();

    expect($session->isActive())->toBeFalse();
});
