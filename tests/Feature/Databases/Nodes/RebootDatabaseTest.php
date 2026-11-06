<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
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

it('reboots standbys one by one, moves the primary away and reboots it last', function () {
    $database = idleDatabase('valkey', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $first, $second] = $database->nodes()->with('server')->get()->all();
    $ssh = fakeSsh()
        ->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")
        ->respond("boot-1\n")->respond('rebooting in 2 seconds')->respond("boot-1\n")->respond("boot-1b\n")->respond()->respond()
        ->respond("boot-2\n")->respond('rebooting in 2 seconds')->respond('', 255)->respond("boot-2b\n")->respond()->respond()
        ->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")
        ->respond()
        ->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")
        ->respond("boot-0\n")->respond('rebooting in 2 seconds')->respond("boot-0b\n")->respond()->respond()
        ->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    $this->post("/databases/{$database->id}/reboot")->assertRedirect()->assertSessionHasNoErrors();

    expect(rebootsIn($ssh))->toBe([$first->server->name, $second->server->name, $primary->server->name])
        ->and($ssh->runs[18]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(18))->toContain('SENTINEL FAILOVER')
        ->and($ssh->runs[23]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(23))->toContain('reboot')
        ->and($ssh->scriptAt(7))->toContain('container_running databasify-valkey || exit 69')
        ->and($ssh->scriptAt(8))->toContain('master_link_status:up')
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($first->fresh()->role)->toBe(NodeRole::Primary)
        ->and($primary->fresh()->role)->toBe(NodeRole::Replica);
    Sleep::assertSleptTimes(5);
});

it('reboots a single node database in place', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("role:master\n")->respond("boot-0\n")->respond()->respond("boot-0b\n")->respond()->respond()->respond("role:master\n");

    $this->post("/databases/{$database->id}/reboot")->assertSessionHasNoErrors();

    expect(rebootsIn($ssh))->toBe([$database->seed()->server->name])
        ->and(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'SENTINEL')))->toBeEmpty()
        ->and($database->fresh()->operation)->toBeNull();
});

it('reboots nothing while a clustered database is degraded', function () {
    $database = idleDatabase('valkey', 3);
    $ssh = fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond('', 255);

    $this->withoutExceptionHandling();

    expect(fn () => $this->post("/databases/{$database->id}/reboot"))->toThrow(RuntimeException::class, 'main is degraded, bring every node back')
        ->and(rebootsIn($ssh))->toBe([])
        ->and($database->fresh()->operation)->toBeNull();
});

it('stops when a server does not come back after its reboot', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("role:master\n")->respond("boot-0\n")->respond();
    foreach (range(1, 180) as $ignored) {
        $ssh->respond('', 255);
    }

    $this->withoutExceptionHandling();

    expect(fn () => $this->post("/databases/{$database->id}/reboot"))->toThrow(RuntimeException::class, 'did not come back within 15 minutes')
        ->and($database->fresh()->operation)->toBeNull();
});

it('waits for the engine container to start after the reboot before checking readiness', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("role:master\n")->respond("boot-0\n")->respond()->respond("boot-0b\n")->respond('', 69)->respond('', 69)->respond()->respond()->respond("role:master\n");

    $this->post("/databases/{$database->id}/reboot")->assertSessionHasNoErrors();

    expect($database->fresh()->operation)->toBeNull()
        ->and($ssh->scriptAt(7))->toContain('wait_for');
    Sleep::assertSleptTimes(3);
});

it('stops when the engine container does not start after the reboot', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("role:master\n")->respond("boot-0\n")->respond()->respond("boot-0b\n");
    foreach (range(1, 12) as $ignored) {
        $ssh->respond('', 69);
    }

    $this->withoutExceptionHandling();

    expect(fn () => $this->post("/databases/{$database->id}/reboot"))->toThrow(RuntimeException::class, 'within 60 seconds of the reboot')
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses to reboot while another operation runs or a server is not ready', function () {
    $database = idleDatabase('valkey', 2);
    Queue::fake([RebootDatabaseJob::class]);
    $database->startOperation('Upgrade');

    $this->post("/databases/{$database->id}/reboot")->assertSessionHasErrors('database');

    $database->finishOperation();
    $database->nodes()->with('server')->get()[1]->server->update(['status' => ServerStatus::Provisioning]);

    $this->post("/databases/{$database->id}/reboot")->assertSessionHasErrors('database');

    Queue::assertNothingPushed();
});
