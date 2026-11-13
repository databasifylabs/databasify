<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\InstallHostBinary;
use App\Engines\Drivers\Postgres\PostgresDriver;
use App\Exceptions\Images\ImageUnavailable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeImageRegistry;

it('installs the newest engine-sdk release inside the required range before every deploy', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    $repository = 'ghcr.io/databasifylabs/engine-sdk';
    imageRegistry()->publishIn($repository, '0.2.1', FakeImageRegistry::digestIn($repository, '0.2.1'))
        ->publishIn($repository, '0.3.0', FakeImageRegistry::digestIn($repository, '0.3.0'))
        ->publishIn($repository, '0.2.2-a1b2c3d', FakeImageRegistry::digestIn($repository, '0.2.2-a1b2c3d'));
    Cache::flush();
    $before = count($ssh->runs);
    $digest = FakeImageRegistry::digestIn($repository, '0.2.1');

    resolve(InstallHostBinary::class)->handle($node);

    expect($ssh->scriptAt($before))
        ->toContain("container=\$(docker create ghcr.io/databasifylabs/engine-sdk@{$digest})")
        ->toContain('docker cp "$container:/databasify" /usr/local/bin/databasify.new || { docker rm "$container" > /dev/null; exit 1; }')
        ->toContain('docker rm "$container" > /dev/null')
        ->not->toContain('--entrypoint')
        ->toContain('chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify')
        ->not->toContain('exit 0');
});

it('fails a deploy before touching the server when the host binary cannot be resolved', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    imageRegistry()->goDown();
    Cache::flush();
    $before = count($ssh->runs);

    expect(fn () => resolve(DeployNode::class)->handle($node->database, $node))->toThrow(ImageUnavailable::class, 'engine-sdk ^0.2')
        ->and(count($ssh->runs))->toBe($before);
});

it('fails a deploy before touching the server when no engine-sdk release satisfies the required range', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    config(['databasify.require.engine-sdk' => '^0.4']);
    Cache::flush();
    $before = count($ssh->runs);

    expect(fn () => resolve(DeployNode::class)->handle($node->database, $node))->toThrow(ImageUnavailable::class, 'No engine-sdk release satisfies ^0.4.')
        ->and(count($ssh->runs))->toBe($before);
});

it('deploys with the host profile of the engine then installs the node certificate', function () {
    $ssh = fakeEngine();
    $node = engineNode();
    $database = $node->database;
    $before = count($ssh->runs);

    resolve(DeployNode::class)->handle($database, $node);

    $database->refresh();
    $deploy = collect($ssh->runs)->search(fn (array $run): bool => str_contains($run['script'], '| databasify host deploy '));

    expect(collect($ssh->runs)->slice($before)->pluck('script')->map(fn (string $script): string => preg_match('/databasify (host deploy|engine info)/', $script, $match) === 1 ? $match[1] : 'script')->all())
        ->toBe(['script', 'host deploy', 'engine info'])
        ->and(json_decode($ssh->engineRequestAt($deploy), true))->toBe([
            'image' => "{$database->image->repository}:{$database->image->release}@{$database->image->digest}",
            'databaseId' => $database->id,
            'nodeId' => $node->id,
            'hostProfile' => json_decode(json_encode(PostgresDriver::facts('18')->hostProfile->toPayload(), JSON_THROW_ON_ERROR), true),
        ])
        ->and(recordedArgs('nodes.installTls')[0]['args'])->toBe([
            $database->settings->nodeCertificate($node->id, 'server.crt'),
            $database->settings->nodeCertificate($node->id, 'server.key'),
            $database->settings->certificate('ca.crt'),
        ]);
});

it('refuses an image built for another engine', function () {
    fakeEngine(fakeSsh()->respondToEngine('host deploy', ['engine' => 'mysql']));
    $node = engineNode();
    $database = $node->database;

    expect(fn () => resolve(DeployNode::class)->handle($database, $node))->toThrow(ImageUnavailable::class, "{$database->image->reference()} is built for mysql, not PostgreSQL.")
        ->and(recordedCalls())->toBe([]);
});

it('fails the deploy when the running engine reports an SDK outside the range', function () {
    fakeEngine(fakeSsh()->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '0.3.0']));
    $node = engineNode();
    $database = $node->database;

    expect(fn () => resolve(DeployNode::class)->handle($database, $node))
        ->toThrow(ImageUnavailable::class, "{$database->engine()->label} {$database->image->release} is built on SDK 0.3.0; this Databasify supports ^0.2.")
        ->and(recordedCalls())->not->toContain('nodes.installTls');
});

it('names the SDK unknown when the running engine reports none', function () {
    fakeEngine(fakeSsh()->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '']));
    $node = engineNode();
    $database = $node->database;

    expect(fn () => resolve(DeployNode::class)->handle($database, $node))
        ->toThrow(ImageUnavailable::class, "{$database->engine()->label} {$database->image->release} is built on SDK unknown; this Databasify supports ^0.2.");
});
