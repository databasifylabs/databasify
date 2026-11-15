<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function runOwnerCommand(string $password, array $options): array
{
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, $password."\n");
    rewind($stdin);
    $input = new ArrayInput(['command' => 'setup:owner', ...$options]);
    $input->setStream($stdin);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    $exitCode = Artisan::handle($input, $output);

    return [$exitCode, $output->fetch()];
}

test('the owner is created with the password read from standard input', function () {
    [$exitCode, $output] = runOwnerCommand('new-password', ['--name' => 'Ada Lovelace', '--email' => 'ada@example.com']);

    $owner = User::query()->sole();
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Owner account ada@example.com created.')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('new-password', $owner->password))->toBeTrue();
});

test('a second owner is refused', function () {
    User::factory()->create();

    [$exitCode, $output] = runOwnerCommand('new-password', ['--name' => 'Mallory', '--email' => 'mallory@example.com']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Databasify is already set up. Sign in instead.')
        ->and(User::query()->count())->toBe(1);
});

test('invalid owner details are refused', function () {
    [$exitCode, $output] = runOwnerCommand('new-password', ['--name' => 'Ada Lovelace', '--email' => 'not-an-email']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('The email field must be a valid email address.')
        ->and(User::query()->exists())->toBeFalse();
});
