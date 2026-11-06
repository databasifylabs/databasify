<?php

declare(strict_types=1);

use App\Actions\Servers\RunTerminalSession;
use App\Events\TerminalOutputReceived;
use App\Jobs\Servers\RunTerminalSessionJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\TerminalSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('opens a terminal on a ready server and queues its shell', function () {
    Queue::fake([RunTerminalSessionJob::class]);
    $server = Server::factory()->ready()->create();

    $id = $this->postJson("/servers/{$server->id}/terminals", ['columns' => 120, 'rows' => 32])->assertCreated()->json('id');

    expect((new TerminalSession($id))->isFor($server, $this->user))->toBeTrue();
    Queue::assertPushed(RunTerminalSessionJob::class, fn (RunTerminalSessionJob $job): bool => $job->session->id === $id && $job->columns === 120 && $job->rows === 32);
});

it('refuses a terminal on a server that is not ready', function () {
    Queue::fake([RunTerminalSessionJob::class]);
    $server = Server::factory()->create();

    $this->postJson("/servers/{$server->id}/terminals", ['columns' => 80, 'rows' => 24])->assertJsonValidationErrors('server');

    Queue::assertNothingPushed();
});

it('passes keystrokes through untouched, spaces and returns included', function () {
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $this->user);

    $this->postJson("/servers/{$server->id}/terminals/{$session->id}/input", ['data' => base64_encode(' ls -la')])->assertNoContent();
    $this->postJson("/servers/{$server->id}/terminals/{$session->id}/input", ['data' => base64_encode("\r")])->assertNoContent();

    expect($session->nextInput())->toBe(" ls -la\r")
        ->and($session->nextInput())->toBe('');
});

it('keeps other users and servers out of a terminal', function () {
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, User::factory()->create());
    $other = TerminalSession::open(Server::factory()->ready()->create(), $this->user);

    $this->postJson("/servers/{$server->id}/terminals/{$session->id}/input", ['data' => base64_encode('id')])->assertNotFound();
    $this->postJson("/servers/{$server->id}/terminals/{$other->id}/input", ['data' => base64_encode('id')])->assertNotFound();
    expect($session->attach($this->user))->toBeFalse();
});

it('stops taking input once the terminal is closed', function () {
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $this->user);

    $this->deleteJson("/servers/{$server->id}/terminals/{$session->id}")->assertNoContent();

    expect($session->isActive())->toBeFalse()
        ->and($session->nextInput())->toBeNull();
    $this->postJson("/servers/{$server->id}/terminals/{$session->id}/input", ['data' => base64_encode('ls')])->assertStatus(410);
});

it('streams the shell to the browser and writes what was typed', function () {
    Event::fake([TerminalOutputReceived::class]);
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $this->user);
    $session->attach($this->user);
    $session->push("uptime\r");
    $ssh = fakeSsh();
    $ssh->shellOutput = "root@db:~# \u{1b}[32mready\u{1b}[0m";

    (new RunTerminalSessionJob($server, $session, 100, 30))->handle(resolve(RunTerminalSession::class));

    expect($ssh->shells)->toBe([['server' => $server->name, 'columns' => 100, 'rows' => 30]])
        ->and($ssh->typed)->toBe(["uptime\r"])
        ->and($session->isActive())->toBeFalse();
    Event::assertDispatched(TerminalOutputReceived::class, fn (TerminalOutputReceived $event): bool => $event->terminal === $session->id && $event->broadcastWith() === ['data' => base64_encode("root@db:~# \u{1b}[32mready\u{1b}[0m"), 'closed' => false]);
    Event::assertDispatched(TerminalOutputReceived::class, fn (TerminalOutputReceived $event): bool => $event->closed);
});

it('reports a terminal session crash', function () {
    Exceptions::fake();
    Event::fake([TerminalOutputReceived::class]);
    $server = Server::factory()->ready()->create();
    $session = TerminalSession::open($server, $this->user);
    $session->attach($this->user);
    fakeSsh()->failWith(new RuntimeException('shell dropped'));

    (new RunTerminalSessionJob($server, $session, 100, 30))->handle(resolve(RunTerminalSession::class));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'shell dropped');
    Event::assertDispatched(TerminalOutputReceived::class, fn (TerminalOutputReceived $event): bool => $event->closed);
    expect($session->isActive())->toBeFalse();
});
