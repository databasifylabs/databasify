<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('retunes a database for its server size over http', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->post(projectUrl("/databases/{$database->id}/retune"))->assertRedirect()->assertSessionHasNoErrors();

    expect($database->fresh()->operation)->toBe('Retune for server size');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('refuses to retune a busy database', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);

    expect(fn () => resolve(RetuneDatabase::class)->handle($database))->toThrow(ValidationException::class, 'busy with Create MySQL database');
    Queue::assertNothingPushed();
});

it('reads the new server size, configures the node with it and clears the retune flag', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['retune_needed_at' => now()]);
    $ssh = fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => str_contains($script, '/etc/os-release'), '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":4,"memory_mb":16384,"disk_gb":160}'."\n"))
        ->respondToEngine('config tune', ['tuned' => ['innodb_buffer_pool_size' => '10624M'], 'restart' => []])->respondToEngine('config apply', []);

    resolve(RetuneDatabase::class)->handle($database);
    advanceFlows();
    $configure = collect($ssh->runs)->keys()->first(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify node configure '));

    expect($database->fresh()->retune_needed_at)->toBeNull()
        ->and($database->seed()->server->fresh()->memory_mb)->toBe(16384)
        ->and(json_decode($ssh->engineRequestAt($configure), true)['members'][0])->cpus->toBe(4)->memoryMb->toBe(16384)
        ->and($database->fresh()->operation)->toBeNull();
});
