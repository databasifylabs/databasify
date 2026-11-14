<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\User;
use Tests\Support\FakeServerConnection;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @return list<string>
 */
function nodeRebootsIn(FakeServerConnection $ssh): array
{
    return collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], "nohup sh -c 'sleep 2; reboot'"))->pluck('server')->values()->all();
}

it('reboots one read-only node and leaves the main alone', function () {
    $ssh = fakeEngine()->bootIdChangesAfterReboot();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $reader = withReadOnlyNode($database);

    $this->post(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/reboot"))->assertSessionHasNoErrors();

    expect(nodeRebootsIn($ssh))->toBe([$reader->server->name])
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('retunes one read-only node and leaves the members alone', function () {
    fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => str_contains($script, '/etc/os-release'), '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n"));
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);

    $this->post(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/retune"))->assertSessionHasNoErrors();

    expect(collect(recordedArgs('config.tune'))->pluck('server')->all())->toBe([$reader->server->name])
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('reboots no member through the node route', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);

    $this->post(projectUrl("/databases/{$database->id}/nodes/{$database->seed()->id}/reboot"))->assertSessionHasErrors('database');
});
