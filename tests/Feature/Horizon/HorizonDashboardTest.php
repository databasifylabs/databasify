<?php

declare(strict_types=1);

use App\Models\User;

it('sends guests to the login page instead of showing the dashboard', function () {
    User::factory()->create();

    $this->get('/horizon')->assertRedirect(route('login'));
});

it('shows the dashboard to a signed-in user, even outside production', function () {
    $this->app['env'] = 'local';

    $this->actingAs(User::factory()->create())->get('/horizon')->assertOk();
});
