<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('sends the home page to the dashboard', function () {
    $this->actingAs(User::factory()->create());
    $dashboard = projectUrl('/dashboard');

    $this->get(route('home'))->assertRedirect($dashboard);
});

it('sends guests to the login page', function () {
    User::factory()->create();
    $this->get(projectUrl('/dashboard'))->assertRedirect(route('login'));
});

it('shows databases, servers and recent logs with where each ran', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $server = $database->seed()->server;
    ServerLog::factory()->for($server)->for($database)->create(['name' => 'Turn on backups']);

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('dashboard')
        ->where('databases.data.0.name', 'cache')
        ->where('servers.data.0.database.name', 'cache')
        ->where('logs.data.0.name', 'Turn on backups')
        ->where('logs.data.0.server', ['id' => $server->id, 'name' => $server->name])
        ->where('logs.data.0.database', ['id' => $database->id, 'name' => 'cache']));
});

it('shows only the latest ten logs', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->ready()->create();
    collect(range(1, 11))->each(fn (int $number) => ServerLog::factory()->for($server)->create(['name' => "Log {$number}", 'created_at' => now()->addMinutes($number)]));

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('logs.data', 10)
        ->where('logs.data.0.name', 'Log 11')
        ->where('logs.data.9.name', 'Log 2')
        ->where('logs.data.0.database', null));
});

it('counts the running logs in the summary', function () {
    $this->actingAs(User::factory()->create());
    ServerLog::factory()->count(2)->create(['status' => ServerLogStatus::Running]);
    ServerLog::factory()->create();

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.running_logs', 2));
});

it('previews ten servers and databases but counts every one in the summary', function () {
    $this->actingAs(User::factory()->create());
    Server::factory()->ready()->count(10)->create();
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')->update(['status' => DatabaseStatus::Ready]);
    withReadOnlyNode(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('servers.data', 10)
        ->has('databases.data', 2)
        ->where('summary', [
            'databases' => ['total' => 2, 'online' => 1],
            'servers' => ['total' => 13, 'in_use' => 3, 'available' => 10],
            'nodes' => ['total' => 3, 'read_only' => 1],
            'running_logs' => 0,
        ]));
});

it('lists the supported operating systems and engines for getting started', function () {
    $this->actingAs(User::factory()->create());

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('requirements.systems', 'Ubuntu 24.04/26.04, Debian 12/13, Rocky Linux 9 or AlmaLinux 9')
        ->where('requirements.engines', 'Valkey, Redis, MySQL, PostgreSQL, MariaDB or MongoDB'));
});
