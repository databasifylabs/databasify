<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

it('sends guests to the login page from every app route', function () {
    User::factory()->create();
    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\') && in_array('auth', $route->gatherMiddleware(), true))
        ->flatMap(fn (Route $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())]));

    expect($routes)->not->toBeEmpty();
    $routes->each(fn (array $route) => $this->call(...$route)->assertRedirect(route('login')));
});
