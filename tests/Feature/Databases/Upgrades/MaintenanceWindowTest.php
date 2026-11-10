<?php

declare(strict_types=1);

use App\Actions\Databases\Upgrades\ApplyMaintenance;
use App\DTOs\Databases\MaintenanceWindow;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    withImage('valkey', '9.1.1');
});

it('finds the next start of a weekly maintenance window in utc', function () {
    $window = new MaintenanceWindow(day: 0, hour: 3);
    $wednesday = CarbonImmutable::parse('2026-10-21 10:00', 'UTC');

    expect($window->nextStartAfter($wednesday)->toIso8601String())->toBe('2026-10-25T03:00:00+00:00')
        ->and($window->nextStartAfter(CarbonImmutable::parse('2026-10-25 03:30', 'UTC'))->toIso8601String())->toBe('2026-11-01T03:00:00+00:00');
});

it('picks the databases whose maintenance window is open', function () {
    $due = idleDatabase('valkey', 1, DatabaseMode::Single, 'due');
    $due->update(['maintenance_day' => 0, 'maintenance_hour' => 3]);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'later')->update(['maintenance_day' => 2, 'maintenance_hour' => 3]);

    expect(Database::query()->inMaintenanceWindowAt(CarbonImmutable::parse('2026-10-25 03:20', 'UTC'))->pluck('name')->all())->toBe(['due']);
});

it('saves the maintenance window and rejects hours outside a day', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);

    $this->put(projectUrl("/databases/{$database->id}/maintenance"), ['day' => 3, 'hour' => 24])->assertSessionHasErrors('hour');
    $this->put(projectUrl("/databases/{$database->id}/maintenance"), ['day' => 3, 'hour' => 22])->assertSessionHasNoErrors();

    expect($database->fresh()->maintenanceWindow())->day->toBe(3)->hour->toBe(22);
});

it('installs the newest release of its line in the maintenance window of a healthy database', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    Queue::fake([AdvanceDatabaseFlow::class]);
    withImage('valkey', '9.1.2');

    resolve(ApplyMaintenance::class)->handle($database);

    expect($database->fresh()->operation)->toBe('Install Valkey 9.1.2');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('leaves degraded databases alone in the maintenance window', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Degraded]);
    withImage('valkey', '9.1.2');
    Queue::fake([AdvanceDatabaseFlow::class]);

    expect(resolve(ApplyMaintenance::class)->handle($database))->toBeNull();
    Queue::assertNothingPushed();
});

it('skips the maintenance window while the registry cannot be reached', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    Queue::fake([AdvanceDatabaseFlow::class]);
    imageRegistry()->goDown();

    expect(resolve(ApplyMaintenance::class)->handle($database))->toBeNull();
    Queue::assertNothingPushed();
});

it('rejects a maintenance day outside the week', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);

    $this->put(projectUrl("/databases/{$database->id}/maintenance"), ['day' => 7, 'hour' => 2])->assertSessionHasErrors('day');
    $this->put(projectUrl("/databases/{$database->id}/maintenance"), [])->assertSessionHasErrors(['day', 'hour']);

    expect($database->fresh()->maintenanceWindow())->day->toBe(0)->hour->toBe(3);
});

it('does nothing in the maintenance window when the database runs the newest release', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    Queue::fake([AdvanceDatabaseFlow::class]);

    expect(resolve(ApplyMaintenance::class)->handle($database))->toBeNull();
    Queue::assertNothingPushed();
});

it('skips a busy database in the maintenance window and keeps the upgrade on offer', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    $database->startOperation('Back up now');
    Queue::fake([AdvanceDatabaseFlow::class]);
    withImage('valkey', '9.1.2');

    resolve(ApplyMaintenance::class)->handle($database);

    expect($database->fresh())->available_image_release->toBe('9.1.2')->operation->toBe('Back up now');
    Queue::assertNothingPushed();
});

it('upgrades only the databases whose maintenance window is open each hour', function () {
    $due = idleDatabase('valkey', 1, DatabaseMode::Single, 'due');
    $due->update(['status' => DatabaseStatus::Ready, 'maintenance_day' => 0, 'maintenance_hour' => 3]);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'later')->update(['status' => DatabaseStatus::Ready, 'maintenance_day' => 0, 'maintenance_hour' => 4]);
    Queue::fake([AdvanceDatabaseFlow::class]);
    withImage('valkey', '9.1.2');
    $this->travelTo(CarbonImmutable::parse('2026-10-25 03:20', 'UTC'));
    $this->artisan('databases:apply-maintenance')->assertSuccessful();

    Queue::assertPushed(AdvanceDatabaseFlow::class, 1);
    Queue::assertPushed(AdvanceDatabaseFlow::class, fn (AdvanceDatabaseFlow $job): bool => $job->flow->database->is($due));
});
