<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\UpdatePersistence;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\Persistence;
use App\Jobs\Databases\ApplyDatabaseConfigJob;
use App\Models\Database;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('turns valkey persistence off live without a restart', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => 'off'])->assertSessionHasNoErrors();
    $database = $database->fresh();

    expect($database->settings->persistence)->toBe(Persistence::Off)
        ->and($database->operation)->toBe('Turn off persistence')
        ->and(renderedFile($database, 'valkey.conf'))->toContain("save \"\"\nappendonly no\n")->not->toContain('appendfsync')
        ->and(view('ssh.engines.valkey.config.apply-config', $database->provider()->scriptData()->liveConfig($database->seed(), ['maxmemory']))->render())
        ->toContain("apply save ''\napply appendonly no\n")->toContain("apply maxmemory '");
    Queue::assertPushed(ApplyDatabaseConfigJob::class, fn (ApplyDatabaseConfigJob $job): bool => $job->keys === ['maxmemory']);
});

it('refuses persistence choices on mysql', function () {
    $mysql = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');

    expect(fn () => $this->withoutExceptionHandling()->put(projectUrl("/databases/{$mysql->id}/persistence"), ['persistence' => 'off']))->toThrow(ValidationException::class, 'always keeps its data on disk');
});

it('turns redis snapshots back on', function () {
    $database = idleDatabase('redis', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withPersistence(Persistence::Off)]);
    Queue::fake([ApplyDatabaseConfigJob::class]);

    resolve(UpdatePersistence::class)->handle($database->fresh(), Persistence::Snapshots);
    $database = $database->fresh();

    expect($database->settings->persistence)->toBe(Persistence::Snapshots)
        ->and($database->operation)->toBe('Turn on snapshots')
        ->and(renderedFile($database, 'redis.conf'))->toContain("save 3600 1\n")->toContain("appendonly yes\n")
        ->and(view('ssh.engines.redis.config.apply-config', $database->provider()->scriptData()->liveConfig($database->seed(), ['maxmemory']))->render())
        ->toContain("apply appendonly yes\napply save '3600 1'\n");
    Queue::assertPushed(ApplyDatabaseConfigJob::class, fn (ApplyDatabaseConfigJob $job): bool => $job->database->is($database) && $job->keys === ['maxmemory']);
});

it('refuses persistence choices on engines that always write to disk', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => 'off'])->assertSessionHasErrors(['persistence' => Database::providerFor($engine)->label().' always keeps its data on disk.']);

    expect($database->fresh()->settings->persistence)->toBe(Persistence::Snapshots);
    Queue::assertNothingPushed();
})->with(['mysql', 'mariadb', 'postgres']);

it('rejects unknown persistence modes', function (mixed $persistence) {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => $persistence])->assertSessionHasErrors('persistence');

    Queue::assertNothingPushed();
})->with(['', 'aof', 'OFF']);

it('keeps persistence while the database is busy', function () {
    $database = newDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplyDatabaseConfigJob::class]);

    expect(fn () => resolve(UpdatePersistence::class)->handle($database, Persistence::Off))->toThrow(ValidationException::class, 'busy with Create Valkey database')
        ->and($database->fresh()->settings->persistence)->toBe(Persistence::Snapshots);
    Queue::assertNothingPushed();
});
