<?php

declare(strict_types=1);

use App\Actions\Servers\DeleteServerUser;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

it('removes the user from the server and forgets it', function () {
    $ssh = fakeSsh();
    $server = Server::factory()->ready()->create();
    $user = $server->users()->create(['username' => 'app']);

    resolve(DeleteServerUser::class)->handle($user);

    expect($server->users()->count())->toBe(0)
        ->and($server->serverLogs()->sole()->name)->toBe('Delete user app')
        ->and($ssh->scriptAt(0))->toContain('userdel --remove app');
});

it('keeps the user when the server is not ready', function () {
    $server = Server::factory()->create();
    $user = $server->users()->create(['username' => 'app']);

    expect(fn () => resolve(DeleteServerUser::class)->handle($user))->toThrow(ValidationException::class, 'is not ready yet.');
    expect($server->users()->count())->toBe(1);
});
