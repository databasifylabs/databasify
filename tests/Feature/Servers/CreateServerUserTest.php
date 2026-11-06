<?php

declare(strict_types=1);

use App\Actions\Servers\CreateServerUser;
use App\Enums\Servers\ServerLogStatus;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

it('records the user and creates it with its key on the server', function () {
    $ssh = fakeSsh();
    $server = Server::factory()->ready()->create();

    resolve(CreateServerUser::class)->handle($server, 'app', 'ssh-ed25519 AAAAC3Nza deploy@laptop');

    expect($server->users()->pluck('username')->all())->toBe(['app'])
        ->and($server->serverLogs()->sole())->name->toBe('Create user app')->status->toBe(ServerLogStatus::Succeeded)
        ->and($ssh->scriptAt(0))
        ->toContain('useradd --create-home --shell /bin/bash app')
        ->toContain("printf '%s\\n' 'ssh-ed25519 AAAAC3Nza deploy@laptop' > \"\$home/.ssh/authorized_keys\"");
});

it('creates a user without a key and does not duplicate a known user', function () {
    $ssh = fakeSsh();
    $server = Server::factory()->ready()->create();
    $server->users()->create(['username' => 'app']);

    resolve(CreateServerUser::class)->handle($server, 'app', null);

    expect($server->users()->count())->toBe(1)
        ->and($ssh->scriptAt(0))->not->toContain('authorized_keys');
});

it('refuses a server that is not ready', function () {
    $server = Server::factory()->create();

    expect(fn () => resolve(CreateServerUser::class)->handle($server, 'app', null))->toThrow(ValidationException::class, 'is not ready yet.');
});
