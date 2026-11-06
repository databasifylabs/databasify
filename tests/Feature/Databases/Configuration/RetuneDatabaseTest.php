<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('retunes a database for its server size over http', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([RetuneDatabaseJob::class]);

    $this->post("/databases/{$database->id}/retune")->assertRedirect()->assertSessionHasNoErrors();

    expect($database->fresh()->operation)->toBe('Retune for server size');
    Queue::assertPushed(RetuneDatabaseJob::class, fn (RetuneDatabaseJob $job): bool => $job->database->is($database));
});

it('refuses to retune a busy database', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([RetuneDatabaseJob::class]);

    expect(fn () => resolve(RetuneDatabase::class)->handle($database))->toThrow(ValidationException::class, 'busy with Create MySQL database');
    Queue::assertNothingPushed();
});

it('reads the new server size, sizes the engine for it and clears the retune flag', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 1, DatabaseMode::Single));
    $database->update(['retune_needed_at' => now()]);
    $database->seed()->server->update(['cpus' => 1, 'memory_mb' => 2048]);
    $database->startOperation('Retune for server size');
    $ssh = fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":4,"memory_mb":16384,"disk_gb":160}'."\n")->respond("PRIMARY ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    expect($database->fresh()->retune_needed_at)->toBeNull()
        ->and($database->seed()->server->fresh()->memory_mb)->toBe(16384)
        ->and(fileWrittenIn($ssh->scriptAt(2), '/srv/mysql/conf/my.cnf'))->toContain("innodb_buffer_pool_size = 10624M\n")->toContain("innodb_read_io_threads = 4\n")
        ->and($database->fresh()->operation)->toBeNull();
});
