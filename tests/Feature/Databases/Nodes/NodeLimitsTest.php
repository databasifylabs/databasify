<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\DTOs\Databases\NewDatabaseNode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('stops adding members past a primary and two standbys', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([AdvanceDatabaseFlow::class]);

    expect(fn () => resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode(Server::factory()->ready()->create())))
        ->toThrow(ValidationException::class, 'already has a primary and 2 standby nodes');
});
