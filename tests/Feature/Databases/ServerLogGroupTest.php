<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

it('sends each log with its group to the database page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1);
    $id = (string) Str::uuid7();
    $database->nodes()->first()->server->serverLogs()->create(['name' => 'a', 'database_id' => $database->id, 'group_id' => $id, 'group_name' => 'updating membership on 1 node']);

    $this->get("/databases/{$database->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('logs.data.0.group', ['id' => $id, 'name' => 'updating membership on 1 node']));
});
