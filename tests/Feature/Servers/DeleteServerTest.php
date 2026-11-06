<?php

declare(strict_types=1);

use App\Actions\Servers\DeleteServer;
use App\Enums\Databases\DatabaseMode;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Validation\ValidationException;

it('deletes the server with its logs', function () {
    $server = Server::factory()->ready()->create();
    ServerLog::factory()->for($server)->create();

    resolve(DeleteServer::class)->handle($server);

    expect(Server::query()->count())->toBe(0)
        ->and(ServerLog::query()->count())->toBe(0);
});

it('refuses to delete a server that belongs to a database', function () {
    $server = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')->seed()->server;

    expect(fn () => resolve(DeleteServer::class)->handle($server))->toThrow(ValidationException::class, 'is part of a database');
    expect($server->fresh())->not->toBeNull();
});
