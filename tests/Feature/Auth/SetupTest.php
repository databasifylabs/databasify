<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to setup while no account exists', function () {
    $this->get(route('login'))->assertRedirect(route('setup'));
});

test('setup screen can be rendered while no account exists', function () {
    $this->get(route('setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/setup'));
});

test('the first owner can be created', function () {
    $response = $this->post(route('setup.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $owner = User::query()->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($owner);
    expect($owner)
        ->email->toBe('ada@example.com')
        ->hasVerifiedEmail()->toBeTrue()
        ->and($owner->lastProject?->name)->toBe('Default');
});

test('setup requires a name, email and confirmed password', function () {
    $this->from(route('setup'))
        ->post(route('setup.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'password'])
        ->assertRedirect(route('setup'));

    expect(User::query()->exists())->toBeFalse();
});

test('setup is closed once an account exists', function () {
    User::factory()->create();

    $this->get(route('setup'))->assertNotFound();
    $this->post(route('setup.store'), [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(1)
        ->and(Project::query()->exists())->toBeFalse();
});
