<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('retunes a database for its server size over http', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([RetuneDatabaseJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/retune"))->assertRedirect()->assertSessionHasNoErrors();

    expect($database->fresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(RetuneDatabaseJob::class);
});

it('refuses to retune a busy database', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([RetuneDatabaseJob::class]);

    expect(fn () => resolve(RetuneDatabase::class)->handle($database))->toThrow(ValidationException::class, 'busy pending');
    Queue::assertNothingPushed();
});

it('reads the new server size, configures the node with it and clears the retune flag', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['retune_needed_at' => now()]);
    fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => str_contains($script, '/etc/os-release'), '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":4,"memory_mb":16384,"disk_gb":160}'."\n"));

    resolve(RetuneDatabase::class)->handle($database);

    expect($database->fresh()->retune_needed_at)->toBeNull()
        ->and($database->seed()->server->fresh()->memory_mb)->toBe(16384)
        ->and(recordedArgs('nodes.configure')[0]['args'][0]->members[0])->cpus->toBe(4)->memoryMb->toBe(16384)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});
