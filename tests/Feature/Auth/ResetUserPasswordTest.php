<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

it('sets the new password', function () {
    $user = User::factory()->create();

    resolve(ResetUserPassword::class)->reset($user, ['password' => 'new-password', 'password_confirmation' => 'new-password']);

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('rejects an unconfirmed password', function () {
    $user = User::factory()->create();

    expect(fn () => resolve(ResetUserPassword::class)->reset($user, ['password' => 'new-password', 'password_confirmation' => 'other']))
        ->toThrow(ValidationException::class);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('shows the reset page with the token and email', function () {
    User::factory()->create();
    $this->get('/reset-password/abc123?email=owner@example.com')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/reset-password')
        ->where('token', 'abc123')
        ->where('email', 'owner@example.com')
        ->has('passwordRules'));
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post('/reset-password', ['token' => Password::createToken($user), 'email' => 'owner@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});
