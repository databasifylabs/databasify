<?php

declare(strict_types=1);

use App\Models\ServerLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('loads only server and database names for the log list', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1);
    ServerLog::factory()->for($database->seed()->server)->for($database)->create();

    DB::enableQueryLog();
    $this->get(projectUrl('/dashboard'))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "name" from "servers"')))->toBeTrue()
        ->and($queries->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "name" from "databases"')))->toBeTrue();
});
