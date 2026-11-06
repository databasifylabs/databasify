<?php

declare(strict_types=1);

use App\DTOs\Databases\ContainerSpec;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;

it('runs the engine image on the host network with its directory mounted and no memory limit', function () {
    withImage('postgres', '18.6', 'sha256:'.str_repeat('c', 64));
    $database = idleDatabase('postgres', 1, DatabaseMode::Single, 'orders');
    $node = $database->seed();
    $reference = 'ghcr.io/databasifylabs/databasify-postgres@sha256:'.str_repeat('c', 64);

    expect(ContainerSpec::for($database, $node)->toRunCommand())->toBe(
        'docker run -d --name databasify-postgres --network host --ipc host --security-opt label=disable --restart unless-stopped --stop-timeout 450'
        .' --ulimit nofile=65536:65536 -v /srv/postgres:/srv/postgres'
        ." --label io.databasify.database={$database->dataKey()} --label io.databasify.node={$database->dataKey()}-{$node->id}"
        ." -e DATABASIFY_IMAGE={$reference} {$reference}",
    );
});

it('waits for every process to stop in turn and quotes what the shell would split', function () {
    withImage('mysql', '8.4.13', 'sha256:'.str_repeat('d', 64));
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->forceFill(['name' => "shop's db"]);
    $command = ContainerSpec::for($database, $database->seed())->toRunCommand();

    expect($command)->toContain('--stop-timeout 1050 ')
        ->toContain("--label 'io.databasify.database=shop'\\''s db-")
        ->toEndWith(' -e DATABASIFY_IMAGE=ghcr.io/databasifylabs/databasify-mysql@sha256:'.str_repeat('d', 64).' ghcr.io/databasifylabs/databasify-mysql@sha256:'.str_repeat('d', 64))
        ->not->toContain('--memory');
});

it('runs the image stored on the database even after a newer release is published', function () {
    withImage('valkey', '9.1.2', 'sha256:'.str_repeat('a', 64));
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.3', 'sha256:'.str_repeat('b', 64));

    expect(ContainerSpec::for($database->fresh(), $database->seed())->image)->toEqual(new EngineImage('ghcr.io/databasifylabs/databasify-valkey', 'sha256:'.str_repeat('a', 64), '9.1.2'));
});
