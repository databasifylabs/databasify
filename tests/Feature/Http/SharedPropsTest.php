<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('links signed-in users to the Horizon dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(projectUrl('/dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('horizonUrl', route('horizon.index')));
});

it('offers no Horizon link before signing in', function () {
    User::factory()->create();
    $this->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('horizonUrl', null));
});
