<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('moves the primary to a standby from the database page', function () {
    $database = idleDatabase('mysql', 2);
    [$primary, $standby] = $database->nodes()->get()->all();
    $ssh = fakeSsh()->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond()->respond("SECONDARY ONLINE\n")->respond("PRIMARY ONLINE\n");

    $this->post(projectUrl("/databases/{$database->id}/failover"))->assertRedirect();

    expect($ssh->runs[2]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(2))->toContain("set_primary_instance('{$standby->privateIp()}:25060')")
        ->and($standby->fresh()->role)->toBe(NodeRole::Primary)
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
