<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\InstallHostBinary;

it('installs the host binary only when missing', function () {
    $ssh = fakeSsh();
    $node = engineNode();
    $before = count($ssh->runs);

    resolve(InstallHostBinary::class)->handle($node);

    expect($ssh->scriptAt($before))
        ->toContain('command -v databasify >/dev/null 2>&1 && exit 0')
        ->toContain("docker run --rm --entrypoint cat {$node->database->image()->reference()} /usr/local/bin/databasify > /usr/local/bin/databasify.new")
        ->toContain('chmod 755 /usr/local/bin/databasify.new && mv /usr/local/bin/databasify.new /usr/local/bin/databasify');
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
