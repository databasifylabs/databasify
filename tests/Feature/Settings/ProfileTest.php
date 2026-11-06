<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
    $this->actingAs($this->user);
});

it('sends the settings root to the profile page', function () {
    $this->get('/settings')->assertRedirect('/settings/profile');
});

it('shows the profile page', function () {
    $this->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/profile')
        ->where('mustVerifyEmail', true));
});

it('updates the name and keeps the email verified', function () {
    $this->patch('/settings/profile', ['name' => 'Admin', 'email' => 'owner@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    expect($this->user->fresh())
        ->name->toBe('Admin')
        ->email_verified_at->not->toBeNull();
});

it('asks to verify a changed email again', function () {
    $this->patch('/settings/profile', ['name' => 'Owner', 'email' => 'new@example.com'])->assertRedirect('/settings/profile');

    expect($this->user->fresh())
        ->email->toBe('new@example.com')
        ->email_verified_at->toBeNull();
});

it('rejects an invalid or taken email', function (string $email) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->patch('/settings/profile', ['name' => '', 'email' => $email])->assertSessionHasErrors(['name', 'email']);

    expect($this->user->fresh()->email)->toBe('owner@example.com');
})->with(['not-an-email', 'taken@example.com']);

it('has no account deletion route', function () {
    $this->delete('/settings/profile', ['password' => 'password'])->assertMethodNotAllowed();

    expect($this->user->fresh())->not->toBeNull();
});
