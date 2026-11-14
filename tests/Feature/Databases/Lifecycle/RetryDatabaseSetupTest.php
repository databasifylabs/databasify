<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('runs the create job of a failed database again', function () {
    $database = idleDatabase('mysql', 3);
    $database->update(['status' => DatabaseStatus::Failed]);
    Queue::fake([CreateDatabaseJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/retry-setup"))->assertSessionHasNoErrors();

    expect($database->fresh()->status)->toBe(DatabaseStatus::Pending);
    Queue::assertPushed(CreateDatabaseJob::class, 1);
});

it('only retries a database whose setup failed', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);

    $this->post(projectUrl("/databases/{$database->id}/retry-setup"))
        ->assertSessionHasErrors(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
});
