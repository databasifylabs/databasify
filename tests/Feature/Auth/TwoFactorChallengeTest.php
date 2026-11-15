<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('two factor challenge redirects to login when not authenticated', function () {
    User::factory()->create();

    $this->get(route('two-factor.login'))->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
});

test('users can sign in with a recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});
