<?php

declare(strict_types=1);

use App\Engines\Flows\RebootNodeFlow;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\FlowState;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeServerConnection;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @return list<string>
 */
function rebootsIn(FakeServerConnection $ssh): array
{
    return collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], "nohup sh -c 'sleep 2; reboot'"))->pluck('server')->values()->all();
}

it('reboots a single node database in place and waits for it to serve as the primary', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeEngine()->bootIdChangesAfterReboot();

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasNoErrors();
    advanceFlows();

    expect(rebootsIn($ssh))->toBe([$database->seed()->server->name])
        ->and(recordedCalls())->not->toContain('cluster.rejoin')
        ->and(recordedArgs('nodes.ready')[0]['args'][1])->toBeTrue()
        ->and($database->fresh()->operation)->toBeNull();
});

it('reboots nothing while a clustered database is degraded', function () {
    $database = idleDatabase('valkey', 3);
    [$primary, $replica, $down] = $database->nodes()->orderBy('position')->get()->map->server;
    $ssh = answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica'), $down, null);

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors(['database' => 'main is degraded, bring every node back before rebooting its servers one by one.']);

    expect(rebootsIn($ssh))->toBe([])
        ->and($database->fresh()->operation)->toBeNull();
});

it('fails the reboot when a server does not come back within 15 minutes', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasNoErrors();
    advanceFlows(3);
    $this->travel(16)->minutes();
    advanceFlows(1);

    expect($database->flows()->latest('id')->where('flow', RebootNodeFlow::class)->sole())
        ->state->toBe(FlowState::Failed)
        ->error->toContain('did not come back within 15 minutes')
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses to reboot while another operation runs or a server is not ready', function () {
    $database = idleDatabase('valkey', 2);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $database->startOperation('Upgrade');

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors('database');

    $database->finishOperation();
    $database->nodes()->with('server')->get()[1]->server->update(['status' => ServerStatus::Provisioning]);

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors('database');

    Queue::assertNothingPushed();
});
