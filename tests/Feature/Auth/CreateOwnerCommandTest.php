<?php

declare(strict_types=1);

use App\Actions\Setup\CreateOwnerAccount;
use App\DTOs\Setup\NewOwner;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  array<string, string>  $options
 * @return array{int, string}
 */
function createOwnerWith(array $options, string $password): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $password);
    rewind($stream);
    $input = new ArrayInput(['command' => 'setup:owner', ...$options]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    return [resolve(Kernel::class)->handle($input, $output), $output->fetch()];
}

it('creates the verified owner from the options and the password on standard input', function () {
    [$status, $output] = createOwnerWith(['--name' => ' Owner ', '--email' => 'owner@example.com'], "long-enough-password\n");

    $owner = User::query()->sole();
    expect($status)->toBe(0)
        ->and($output)->toContain('Owner account owner@example.com created. Setup is closed.')
        ->and($owner->name)->toBe('Owner')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('long-enough-password', $owner->password))->toBeTrue();
});

it('closes the setup page once the owner exists', function () {
    createOwnerWith(['--name' => 'Owner', '--email' => 'owner@example.com'], 'long-enough-password');

    $this->get('/setup')->assertNotFound();
});

it('fails loudly and creates nothing on invalid input', function (array $options, string $password, string $error) {
    [$status, $output] = createOwnerWith($options, $password);

    expect($status)->toBe(1)
        ->and($output)->toContain($error)
        ->and(User::query()->count())->toBe(0);
})->with([
    'missing name' => [['--email' => 'owner@example.com'], 'long-enough-password', 'The name field is required.'],
    'invalid email' => [['--name' => 'Owner', '--email' => 'not-an-email'], 'long-enough-password', 'The email field must be a valid email address.'],
    'empty password' => [['--name' => 'Owner', '--email' => 'owner@example.com'], '', 'The password field is required.'],
]);

it('refuses a second owner', function () {
    User::factory()->create();

    [$status, $output] = createOwnerWith(['--name' => 'Stranger', '--email' => 'stranger@example.com'], 'long-enough-password');

    expect($status)->toBe(1)
        ->and($output)->toContain('Databasify is already set up. Sign in instead.')
        ->and(User::query()->count())->toBe(1);
});

it('creates the owner under one lock so two setups cannot both succeed', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(30)->andReturnTrue()->ordered();
    $lock->shouldReceive('release')->once()->andReturnTrue()->ordered();
    Cache::shouldReceive('lock')->once()->with('setup:owner', 30)->andReturn($lock);

    resolve(CreateOwnerAccount::class)->handle(new NewOwner('Owner', 'owner@example.com', 'long-enough-password'));

    expect(User::query()->count())->toBe(1);
});
