<?php

declare(strict_types=1);

use App\Actions\Setup\CreateOwnerAccount;
use App\DTOs\Setup\NewOwner;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

it('sends every visitor to setup until the owner account exists', function (string $path) {
    $this->get($path)->assertRedirect(route('setup'));
})->with(['/', '/dashboard', '/login', '/0199a1b2-0000-7000-8000-000000000000/servers']);

it('shows the setup page with the password rules', function () {
    $this->get(route('setup'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/setup')
        ->has('passwordRules'));
});

it('creates a verified owner account and signs it in', function () {
    $this->post(route('setup.store'), ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertRedirect(route('home'));

    $owner = User::query()->sole();
    expect($owner->name)->toBe('Owner')
        ->and($owner->email)->toBe('owner@example.com')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeTrue();
    $this->assertAuthenticatedAs($owner);
});

it('validates the owner account', function () {
    $this->post(route('setup.store'), ['name' => '', 'email' => 'not-an-email', 'password' => 'password', 'password_confirmation' => 'other'])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('closes setup for good once the owner account exists', function () {
    User::factory()->create();

    $this->get(route('setup'))->assertNotFound();
    $this->post(route('setup.store'), ['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
    $this->get('/login')->assertOk();

    expect(User::query()->count())->toBe(1);
});

it('refuses a second owner even when called directly', function () {
    User::factory()->create();

    expect(fn () => resolve(CreateOwnerAccount::class)->handle(new NewOwner('Stranger', 'stranger@example.com', 'password')))
        ->toThrow(ValidationException::class, 'Databasify is already set up. Sign in instead.');
});

it('offers no public registration', function () {
    User::factory()->create();

    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();

    expect(User::query()->count())->toBe(1);
});

it('throttles setup submissions', function () {
    collect(range(1, 6))->each(fn () => $this->post(route('setup.store'))->assertStatus(302));

    $this->post(route('setup.store'))->assertTooManyRequests();
});
