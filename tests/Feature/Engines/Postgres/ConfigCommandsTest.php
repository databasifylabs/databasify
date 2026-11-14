<?php

declare(strict_types=1);

use App\Exceptions\Engines\EngineBusy;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = engineNode();
    $this->node->database->update(['version_line' => '18']);
    $this->node = $this->node->fresh();
    $this->savedRequest = json_encode(goFixture('configure-standalone.json')['request']);
});

it('reloads and syncs the configuration through their scripts', function () {
    $this->node->driver()->config()->apply();
    $this->node->driver()->config()->sync();

    $this->scripts->assertRan('postgres/config/apply', fn (string $script): bool => str_contains($script, 'unit_do reload databasify-patroni') && str_contains($script, 'SELECT pg_reload_conf()'))
        ->assertRan('postgres/config/sync', fn (string $script): bool => str_contains($script, 'edit-config --force --replace /srv/postgres/conf/dcs.yml') && str_contains($script, 'restart main --role primary --force'));
});

it('lists the settings waiting for a restart', function () {
    $this->scripts->respond('postgres/config/show', ['values' => [['key' => 'shared_buffers', 'value' => '128MB', 'pendingRestart' => true], ['key' => 'work_mem', 'value' => '4MB', 'pendingRestart' => false]]]);

    $show = $this->node->driver()->config()->pendingRestart();

    expect($show)->toBe(['shared_buffers']);
    $this->scripts->assertRan('postgres/config/show', fn (string $script, array $env): bool => str_starts_with($env['KEYS'], 'max_connections,shared_buffers,'));
});

it('offers the plain catalog before the node is configured', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);

    $fields = $this->node->driver()->config()->fields();

    expect(collect($fields)->firstWhere('key', 'shared_buffers')->default)->toBe('128MB');
});

it('offers tuned defaults once the node is configured', function () {
    $ssh = respondToFileGet(fakeSsh(), 'conf/request.json', $this->savedRequest);
    respondToFileGet($ssh, 'conf/tuned.json', null)->respondToEngine('file put', []);

    $fields = $this->node->driver()->config()->fields();

    expect(collect($fields)->firstWhere('key', 'shared_buffers')->default)->toBe('1024MB')
        ->and($ssh->scriptAt(2))->toContain('databasify file put');
});

it('retunes from the saved request and reports settings that need a restart', function () {
    $ssh = respondToFileGet(fakeSsh(), 'conf/request.json', $this->savedRequest);
    respondToFileGet($ssh, 'conf/tuned.json', '{"shared_buffers":"64MB"}')->respondToEngine('file put', []);
    $this->scripts->respond('postgres/nodes/configure', ['changed' => true]);

    $tune = $this->node->driver()->config()->tune();

    expect($tune)->toContain('shared_buffers')->not->toContain('max_connections');
    $this->scripts->assertRan('postgres/nodes/configure')->assertRan('postgres/config/sync')->assertRan('postgres/config/apply');
});

it('cannot retune a node that was never configured', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);

    expect(fn () => $this->node->driver()->config()->tune())->toThrow(EngineBusy::class, 'run node configure first');
});
