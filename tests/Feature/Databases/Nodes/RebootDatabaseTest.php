<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Servers\ServerStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Event;
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

    expect(rebootsIn($ssh))->toBe([$database->seed()->server->name])
        ->and(recordedCalls())->not->toContain('cluster.rejoin')
        ->and(recordedArgs('nodes.ready')[0]['args'][1])->toBeTrue()
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('reboots nothing while a clustered database is degraded', function () {
    $database = idleDatabase('valkey', 3);
    [$primary, $replica, $down] = $database->nodes()->orderBy('position')->get()->map->server;
    $ssh = answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica'), $down, null);

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors(['database' => 'main is degraded, bring every node back before rebooting its servers one by one.']);

    expect(rebootsIn($ssh))->toBe([])
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('fails the reboot when a server does not come back within 15 minutes', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    fakeEngine();

    runOperation(fn () => $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasNoErrors());

    expect($database->fresh()->status)->toBe(DatabaseStatus::Ready);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => str_contains($alert->message, 'did not come back within 15 minutes'));
});

it('refuses to reboot while another operation runs or a server is not ready', function () {
    $database = idleDatabase('valkey', 2);
    Queue::fake([RebootDatabaseJob::class]);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors('database');

    $database->markReady();
    $database->nodes()->with('server')->get()[1]->server->update(['status' => ServerStatus::Provisioning]);

    $this->post(projectUrl("/databases/{$database->id}/reboot"))->assertSessionHasErrors('database');

    Queue::assertNothingPushed();
});
