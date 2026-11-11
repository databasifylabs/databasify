<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\InstallHostBinary;
use App\Exceptions\Images\ImageUnavailable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeImageRegistry;

it('installs the pinned host binary before every deploy', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    $before = count($ssh->runs);
    $digest = FakeImageRegistry::digestIn('ghcr.io/databasifylabs/databasify-host', '0.1.0');

    resolve(InstallHostBinary::class)->handle($node);

    expect($ssh->scriptAt($before))
        ->toContain("docker run --rm --entrypoint cat ghcr.io/databasifylabs/databasify-host@{$digest} /databasify > /usr/local/bin/databasify.new")
        ->toContain('chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify')
        ->not->toContain('exit 0');
});

it('fails a deploy before touching the server when the host binary cannot be resolved', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    imageRegistry()->goDown();
    Cache::flush();
    $before = count($ssh->runs);

    expect(fn () => resolve(DeployNode::class)->handle($node->database, $node))->toThrow(ImageUnavailable::class, 'databasify-host:0.1.0')
        ->and(count($ssh->runs))->toBe($before);
});

it('deploys then installs the node certificate', function () {
    $ssh = fakeSsh()
        ->respondToEngine('host deploy', ['engine' => 'postgres', 'container' => 'databasify-postgres', 'created' => true, 'replaced' => false])
        ->respondToEngine('tls install', []);
    $node = engineNode();
    $database = $node->database;
    $before = count($ssh->runs);

    resolve(DeployNode::class)->handle($database, $node);

    $engineCalls = collect($ssh->runs)->slice($before)->keys()->map(fn (int $index): string => $ssh->engineRequestAt($index))->filter()->values();
    $database->refresh();

    expect(collect($ssh->runs)->slice($before)->pluck('script')->map(fn (string $script): string => preg_match('/databasify (host deploy|tls install)/', $script, $match) === 1 ? $match[1] : 'script')->all())
        ->toBe(['script', 'host deploy', 'tls install'])
        ->and(json_decode($engineCalls[0], true))->toBe(['image' => "{$database->image_repository}:{$database->image_release}@{$database->image_digest}", 'databaseId' => $database->id, 'nodeId' => $node->id])
        ->and(json_decode($engineCalls[1], true))->toBe([
            'certificate' => $database->settings->nodeCertificate($node->id, 'server.crt'),
            'key' => $database->settings->nodeCertificate($node->id, 'server.key'),
            'ca' => $database->settings->certificate('ca.crt'),
        ]);
});
