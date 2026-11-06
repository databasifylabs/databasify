<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('reads the star count from GitHub and asks at most once an hour', function () {
    Http::fake(['api.github.com/repos/databasifylabs/databasify' => Http::response(['stargazers_count' => 1234])]);

    $this->getJson('/repository/stars')->assertOk()->assertExactJson(['stars' => 1234]);
    $this->getJson('/repository/stars')->assertOk()->assertExactJson(['stars' => 1234]);
    Http::assertSentCount(1);

    $this->travel(61)->minutes();
    $this->getJson('/repository/stars')->assertOk();
    Http::assertSentCount(2);
});

it('shows no count while GitHub has no public repository, and retries later', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->getJson('/repository/stars')->assertOk()->assertExactJson(['stars' => null]);
    $this->getJson('/repository/stars')->assertOk()->assertExactJson(['stars' => null]);
    Http::assertSentCount(1);

    $this->travel(11)->minutes();
    $this->getJson('/repository/stars')->assertOk();
    Http::assertSentCount(2);
});

it('keeps the star count behind the login', function () {
    auth()->logout();

    $this->getJson('/repository/stars')->assertUnauthorized();
});
