<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('links signed-in users to the Horizon dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(projectUrl('/dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('horizonUrl', route('horizon.index')));
});

it('shares the running Databasify version', function () {
    config(['app.version' => '1.2.3']);
    User::factory()->create();

    $this->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('version', '1.2.3'));
});

it('offers no Horizon link before signing in', function () {
    User::factory()->create();
    $this->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('horizonUrl', null));
});

it('shares whether a newer release is available', function (?string $release, bool $available) {
    installedUpdater();
    config(['app.version' => '0.1.0']);
    if ($release !== null) {
        storeRelease($release, '');
    }

    $this->actingAs(User::factory()->create())
        ->get(projectUrl('/dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('updateAvailable', $available));
})->with([['0.2.0', true], ['0.1.0', false], [null, false]]);

it('shares no update for guests', function () {
    storeRelease('0.2.0', '');
    User::factory()->create();

    $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('updateAvailable', false));
});

it('shows updates only on an install with the updater', function (bool $installed) {
    config()->set('databasify.updates.path', $installed ? installedUpdater() : temporaryDirectory());
    config(['app.version' => '0.1.0']);
    storeRelease('0.2.0', '');

    $this->actingAs(User::factory()->create())
        ->get(projectUrl('/dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('updatesEnabled', $installed)->where('updateAvailable', $installed));
})->with(['docker install' => true, 'local install' => false]);
