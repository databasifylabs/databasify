<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('asks to confirm the password before showing security settings', function () {
    $this->get('/settings/security')->assertRedirect(route('password.confirm'));
});

it('shows security settings once the password is confirmed', function () {
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', true)
            ->where('canManagePasskeys', true)
            ->where('passkeys', [])
            ->where('twoFactorEnabled', false)
            ->where('requiresConfirmation', true)
            ->has('passwordRules'));
});

it('updates the password with the current password', function () {
    $this->from('/settings/security')
        ->put('/settings/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/security');

    expect(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue();
});

it('rejects a wrong current password or an unconfirmed new one', function () {
    $this->put('/settings/password', ['current_password' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'other-password'])
        ->assertSessionHasErrors(['current_password', 'password']);

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});
