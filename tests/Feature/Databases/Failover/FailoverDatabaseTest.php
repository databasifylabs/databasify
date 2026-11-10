<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('moves the primary to a standby from the database page', function () {
    $database = idleDatabase('mysql', 2);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'));

    $this->post(projectUrl("/databases/{$database->id}/failover"))->assertRedirect();
    $failover = collect($ssh->runs)->search(fn (array $run): bool => str_contains($run['script'], '| databasify cluster failover '));

    expect($ssh->runs[$failover]['server'])->toBe($primary->server->name)
        ->and(json_decode($ssh->engineRequestAt($failover), true))->toBe(['target' => $standby->privateIp()])
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->where('name', "moving the primary off {$primary->server->name}")->first())->status->toBe(ServerLogStatus::Succeeded);
});

it('refuses to fail over without a standby member', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withReadOnlyNode($database);
    Queue::fake([FailoverDatabaseJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/failover"))->assertSessionHasErrors(['database' => 'Failover needs at least one standby node.']);

    Queue::assertNothingPushed();
});

it('refuses to fail over while another operation runs', function () {
    $database = idleDatabase('valkey', 2);
    Queue::fake([FailoverDatabaseJob::class]);
    $database->startOperation('Upgrade');

    $this->post(projectUrl("/databases/{$database->id}/failover"))->assertSessionHasErrors('database');

    Queue::assertNothingPushed();
});
