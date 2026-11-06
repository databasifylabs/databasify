<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => User::factory()->create());

it('shows the login page with the reset link and status', function () {
    $this->withSession(['status' => 'Your password has been reset.'])
        ->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->where('status', 'Your password has been reset.'));
});

it('signs the administrator in and sends them to the dashboard', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'password'])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('shows the forgot password page', function () {
    $this->get(route('password.request'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/forgot-password'));
});

it('sends an unverified administrator to the verify email page', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/verify-email'));
});

it('shows the confirm password page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('password.confirm'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/confirm-password'));
});

it('shows the two factor challenge during a two factor login', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->id])
        ->get(route('two-factor.login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/two-factor-challenge'));
});

it('advertises where passkeys are managed', function () {
    $this->get('/.well-known/passkey-endpoints')->assertOk()->assertExactJson([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
});

it('locks out sign in after five failed attempts', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    collect(range(1, 5))->each(fn () => $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'wrong-password']));

    $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();
});
