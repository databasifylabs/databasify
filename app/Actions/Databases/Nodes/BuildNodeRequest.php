<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\Data\Member;
use App\Engines\Data\Secrets;
use App\Engines\Requests\NodeConfigure;
use App\Exceptions\Databases\ServerSizeUnknown;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class BuildNodeRequest
{
    public function handle(Database $database, DatabaseNode $node, ?DatabaseNode $without = null): NodeConfigure
    {
        $nodes = $this->nodesOf($database, $without);
        $primary = $nodes->first(fn (DatabaseNode $candidate): bool => $candidate->isPrimary()) ?? $nodes->firstOrFail();

        return new NodeConfigure(
            clustered: $nodes->count() > 1,
            readOnly: $node->isReadOnly(),
            ip: $node->privateIp(),
            databaseId: $database->id,
            members: $this->membersAmong($nodes),
            settings: array_filter($database->settings->config, fn (string $value): bool => $value !== ''),
            secrets: Secrets::of($database->settings),
            primary: $primary->privateIp(),
        );
    }

    /**
     * @return list<Member>
     */
    public function membersOf(Database $database, ?DatabaseNode $without = null): array
    {
        return $this->membersAmong($this->nodesOf($database, $without));
    }

    /**
     * @return list<Member>
     */
    private function membersAmong(DatabaseNodeCollection $nodes): array
    {
        return array_values($nodes->toBase()->map($this->memberOf(...))->all());
    }

    private function nodesOf(Database $database, ?DatabaseNode $without): DatabaseNodeCollection
    {
        return $database->nodes()->with('server')->when($without, fn ($query, DatabaseNode $leaving) => $query->whereKeyNot($leaving->id))->orderBy('position')->get();
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
