<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\OperatingSystem;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\CreateServerUserJob;
use App\Jobs\Servers\DeleteServerUserJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\User;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([ProvisionServerJob::class, CreateServerUserJob::class, DeleteServerUserJob::class, UpdateServerPackagesJob::class, RebootServerJob::class]);
    $this->actingAs(User::factory()->create());
});

it('lists servers', function () {
    Server::factory()->ready()->create(['name' => 'db-1']);

    $this->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('servers/index')
        ->where('servers.data.0.name', 'db-1'));
});

it('shows the distribution each server runs', function (array $attributes, ?string $distribution) {
    Server::factory()->ready()->create($attributes);

    $this->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('servers.data.0.distribution', $distribution));
})->with([
    'ordered image' => [['image' => OperatingSystem::Rocky9, 'os' => null], 'rocky'],
    'reported by an added server' => [['image' => null, 'os' => 'Debian GNU/Linux 13 (trixie)'], 'debian'],
    'two-word distribution' => [['image' => null, 'os' => 'AlmaLinux 9.6 (Sage Margay)'], 'almalinux'],
    'unknown system' => [['image' => null, 'os' => 'Arch Linux'], null],
    'not read yet' => [['image' => null, 'os' => null], null],
]);

it('shows which database each server belongs to', function () {
    $node = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')->seed();

    $this->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('servers.data.0.database', ['id' => $node->database_id, 'name' => 'cache', 'engine' => 'valkey', 'engine_icon' => asset('assets/icons/database-providers/valkey.svg'), 'kind' => 'member', 'node_id' => $node->id]));
});

it('shows the app public key when adding a server', function () {
    $this->get('/servers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('servers/create')
        ->where('publicKey', fn (string $key) => str_starts_with($key, 'ssh-ed25519 ')));
});

it('adds a server and queues provisioning', function () {
    $this->post('/servers', [
        'name' => 'db-1',
        'public_ip' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_password' => 'secret',
        'disable_password_login' => true,
    ])->assertRedirect('/servers/'.Server::query()->sole()->id);

    Queue::assertPushed(ProvisionServerJob::class);
});

it('rejects names and users that are unsafe in shell scripts', function () {
    $this->post('/servers', [
        'name' => 'db;rm',
        'public_ip' => 'not-an-ip',
        'ssh_port' => 22,
        'ssh_user' => 'root;id',
    ])->assertSessionHasErrors(['name', 'public_ip', 'ssh_user']);
});

it('rejects a server name or address that is already taken', function () {
    Server::factory()->create(['name' => 'db-1', 'public_ip' => '203.0.113.10']);

    $this->post('/servers', ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 70000, 'ssh_user' => 'root'])
        ->assertSessionHasErrors(['name', 'public_ip', 'ssh_port']);

    expect(Server::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('shows a server with its users and recent logs', function () {
    $server = Server::factory()->ready()->create(['name' => 'db-1']);
    $server->users()->create(['username' => 'zed']);
    $server->users()->create(['username' => 'app']);
    ServerLog::factory()->for($server)->create(['name' => 'Reboot', 'output' => 'rebooting', 'exit_code' => 0]);

    $this->get("/servers/{$server->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('servers/show')
        ->where('server.data.name', 'db-1')
        ->where('server.data.database', null)
        ->where('users.0.username', 'app')
        ->where('users.1.username', 'zed')
        ->where('logs.data.0.name', 'Reboot')
        ->where('logs.data.0.status', 'succeeded')
        ->missing('logs.data.0.output')
        ->where('logs.data.0.exit_code', 0)
        ->where('logs.data.0.server', ['id' => $server->id, 'name' => 'db-1'])
        ->where('logs.data.0.database', null)
        ->where('log.name', 'Reboot')
        ->where('log.output', 'rebooting'));
});

it('shows the output of the chosen log of the server only', function () {
    $server = Server::factory()->ready()->create();
    $older = ServerLog::factory()->for($server)->create(['name' => 'Older', 'output' => 'older output', 'created_at' => now()->subMinute()]);
    ServerLog::factory()->for($server)->create(['name' => 'Newer', 'output' => 'newer output']);
    $elsewhere = ServerLog::factory()->create(['output' => 'other server']);

    $this->get("/servers/{$server->id}?log={$older->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('log.name', 'Older')
        ->where('log.output', 'older output'));

    $this->get("/servers/{$server->id}?log={$elsewhere->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('log', null));
});

it('shows no log output before anything ran', function () {
    $server = Server::factory()->ready()->create();

    $this->get("/servers/{$server->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('logs.data', 0)
        ->where('log', null)
        ->where('latestLogId', null));
});

it('points to the newest log of the server on every page of its logs', function () {
    $server = Server::factory()->ready()->create();
    ServerLog::factory()->for($server)->count(ServerLogQuery::PER_PAGE)->create(['created_at' => now()->subHour()]);
    $newest = ServerLog::factory()->for($server)->create();
    ServerLog::factory()->create(['created_at' => now()->addMinute()]);

    $firstPage = $this->get("/servers/{$server->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('latestLogId', $newest->id));

    $this->get($firstPage->inertiaProps('logs.links.next'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('logs.data', 1)
        ->where('latestLogId', $newest->id));
});

it('returns not found for a server that does not exist', function () {
    $this->get('/servers/999')->assertNotFound();
});

it('deletes a server', function () {
    $server = Server::factory()->ready()->create();

    $this->delete("/servers/{$server->id}")->assertRedirect('/servers');

    expect(Server::query()->count())->toBe(0);
});

it('refuses to delete a server that belongs to a database', function () {
    $server = idleDatabase('valkey', 1, DatabaseMode::Single)->seed()->server;

    $this->delete("/servers/{$server->id}")->assertSessionHasErrors('server');

    expect($server->fresh())->not->toBeNull();
});

it('lists logs newest first', function () {
    $server = Server::factory()->ready()->create();
    $at = fn (string $time) => Date::parse("2026-10-25 {$time}");
    ServerLog::factory()->for($server)->create(['name' => 'Read server facts', 'created_at' => $at('10:00')]);
    ServerLog::factory()->for($server)->create(['name' => 'Reboot', 'created_at' => $at('10:05')]);
    ServerLog::factory()->for($server)->create(['name' => 'Updating packages', 'created_at' => $at('10:02')]);
    ServerLog::factory()->create(['name' => 'Another server', 'created_at' => $at('10:06')]);

    $this->get("/servers/{$server->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('logs.data', fn ($logs) => collect($logs)->pluck('name')->all() === ['Reboot', 'Updating packages', 'Read server facts']));
});

it('pages the server list and summarises every server', function () {
    Server::factory()->ready()->count(26)->create();
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $first = $this->get('/servers');

    $first->assertInertia(fn (AssertableInertia $page) => $page
        ->has('servers.data', 25)
        ->where('servers.links.prev', null)
        ->where('summary', ['total' => 27, 'in_use' => 1, 'available' => 26]));

    $this->get($first->inertiaProps('servers.links.next'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('servers.data', 2)
        ->where('servers.links.next', null));
});

it('searches servers by name or address and filters them by status', function (string $query, array $names) {
    Server::factory()->ready()->create(['name' => 'db-sgp-01', 'public_ip' => '203.0.113.21']);
    Server::factory()->ready()->create(['name' => 'web-01', 'public_ip' => '198.51.100.7']);
    Server::factory()->create(['name' => 'db-fra-01', 'public_ip' => '203.0.113.40', 'status' => ServerStatus::Failed]);

    $this->get("/servers?{$query}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('servers.data', fn ($servers) => collect($servers)->pluck('name')->sort()->values()->all() === $names)
        ->where('summary.total', 3));
})->with([
    'by name' => ['search=db-', ['db-fra-01', 'db-sgp-01']],
    'by address' => ['search=198.51', ['web-01']],
    'by status' => ['status=failed', ['db-fra-01']],
    'search and status together' => ['search=203.0.113&status=ready', ['db-sgp-01']],
    'unknown status is ignored' => ['status=nonsense', ['db-fra-01', 'db-sgp-01', 'web-01']],
]);

it('echoes the active filters and keeps them on the next page link', function () {
    Server::factory()->ready()->count(26)->create();

    $response = $this->get('/servers?status=ready&search=');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters', ['search' => '', 'status' => 'ready'])
        ->where('filterOptions.statuses', array_map(fn (ServerStatus $status) => $status->value, ServerStatus::cases())));
    expect($response->inertiaProps('servers.links.next'))->toContain('status=ready');
});
