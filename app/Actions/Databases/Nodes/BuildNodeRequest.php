<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\Data\Member;
use App\Engines\Data\Secrets;
use App\Engines\Requests\NodeConfigure;
use App\Exceptions\Databases\ServerSizeUnknown;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class BuildNodeRequest
{
    public function handle(Database $database, DatabaseNode $node): NodeConfigure
    {
        $nodes = $database->nodes()->with('server')->orderBy('position')->get();

        return new NodeConfigure(
            clustered: $nodes->count() > 1,
            readOnly: $node->isReadOnly(),
            ip: $node->privateIp(),
            databaseId: $database->id,
            members: array_values($nodes->map($this->memberOf(...))->all()),
            settings: array_filter($database->settings->config, fn (string $value): bool => $value !== ''),
            secrets: Secrets::of($database->settings),
            primary: $database->primaryOrSeed()->privateIp(),
        );
    }

    private function memberOf(DatabaseNode $node): Member
    {
        $server = $node->server;

        if ($server->cpus === null || $server->memory_mb === null || $server->disk_gb === null) {
            throw ServerSizeUnknown::of($server);
        }

        return new Member($node->privateIp(), $node->position, ! $node->isReadOnly(), $server->cpus, $server->memory_mb, $server->disk_gb);
    }
}
