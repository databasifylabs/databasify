<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('finds databases by name and by engine', function () {
    idleDatabase('postgres', 1, DatabaseMode::Single, 'analytics', '18');
    $orders = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');

    $this->getJson('/search?q=ord')->assertOk()->assertJson(['results' => [[
        'kind' => 'database',
        'title' => 'orders',
        'subtitle' => $orders->versionLabel(),
        'url' => route('databases.show', $orders),
    ]]]);
    $this->getJson('/search?q=postgres')->assertOk()->assertJsonPath('results.0.title', 'analytics')->assertJsonCount(1, 'results');
});

it('finds servers by name and address', function () {
    $server = Server::factory()->ready()->create(['name' => 'db-sgp-01', 'public_ip' => '203.0.113.21']);
    Server::factory()->ready()->create(['name' => 'web-01', 'public_ip' => '198.51.100.7']);

    $this->getJson('/search?q=203.0.113')->assertOk()->assertJson(['results' => [[
        'kind' => 'server',
        'title' => 'db-sgp-01',
        'subtitle' => '203.0.113.21',
        'url' => route('servers.show', $server),
    ]]])->assertJsonCount(1, 'results');
});

it('needs a search term', function () {
    $this->getJson('/search')->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('keeps search behind the login', function () {
    auth()->logout();

    $this->getJson('/search?q=orders')->assertUnauthorized();
});
