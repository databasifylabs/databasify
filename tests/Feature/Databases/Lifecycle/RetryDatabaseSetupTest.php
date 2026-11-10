<?php

declare(strict_types=1);

use App\Engines\Flows\CreateDatabaseFlow;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('runs the create flow of a failed database again', function () {
    $database = idleDatabase('mysql', 3);
    $database->update(['status' => DatabaseStatus::Failed]);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->post(projectUrl("/databases/{$database->id}/retry-setup"))->assertSessionHasNoErrors();

    expect($database->fresh()->status)->toBe(DatabaseStatus::Creating)
        ->and($database->fresh()->operation)->toBe('Create MySQL database')
        ->and($database->flows()->running()->sole()->flow)->toBe(CreateDatabaseFlow::class);
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('only retries a database whose setup failed', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);

    $this->post(projectUrl("/databases/{$database->id}/retry-setup"))
        ->assertSessionHasErrors(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
});
