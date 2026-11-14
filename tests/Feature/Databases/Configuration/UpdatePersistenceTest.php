<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\UpdatePersistence;
use App\Engines\EngineCatalog;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\Persistence;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('turns valkey persistence off with the appendonly setting', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplySettingsJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => 'off'])->assertSessionHasNoErrors();
    $database = $database->fresh();

    expect($database->settings->persistence)->toBe(Persistence::Off)
        ->and($database->settings->config)->toBe(['appendonly' => 'no'])
        ->and($database->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplySettingsJob::class);
});

it('refuses persistence choices on mysql', function () {
    $mysql = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');

    expect(fn () => $this->withoutExceptionHandling()->put(projectUrl("/databases/{$mysql->id}/persistence"), ['persistence' => 'off']))->toThrow(ValidationException::class, 'always keeps its data on disk');
});

it('turns redis snapshots back on by dropping the appendonly setting', function () {
    $database = idleDatabase('redis', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withPersistence(Persistence::Off)->withConfig(['appendonly' => 'no', 'timeout' => '300'])]);
    Queue::fake([ApplySettingsJob::class]);

    resolve(UpdatePersistence::class)->handle($database->fresh(), Persistence::Snapshots);
    $database = $database->fresh();

    expect($database->settings->persistence)->toBe(Persistence::Snapshots)
        ->and($database->settings->config)->toBe(['timeout' => '300'])
        ->and($database->status)->toBe(DatabaseStatus::Updating);
});

it('refuses persistence choices on engines that always write to disk', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([ApplySettingsJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => 'off'])->assertSessionHasErrors(['persistence' => EngineCatalog::find($engine)->label.' always keeps its data on disk.']);

    expect($database->fresh()->settings->persistence)->toBe(Persistence::Snapshots);
    Queue::assertNothingPushed();
})->with(['mysql', 'mariadb', 'postgres']);

it('rejects unknown persistence modes', function (mixed $persistence) {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplySettingsJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/persistence"), ['persistence' => $persistence])->assertSessionHasErrors('persistence');

    Queue::assertNothingPushed();
})->with(['', 'aof', 'OFF']);

it('keeps persistence while the database is busy', function () {
    $database = newDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplySettingsJob::class]);

    expect(fn () => resolve(UpdatePersistence::class)->handle($database, Persistence::Off))->toThrow(ValidationException::class, 'busy pending')
        ->and($database->fresh()->settings->persistence)->toBe(Persistence::Snapshots);
    Queue::assertNothingPushed();
});
