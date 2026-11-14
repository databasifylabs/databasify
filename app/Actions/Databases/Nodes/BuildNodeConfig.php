<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Secrets;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class BuildNodeConfig
{
    public function handle(Database $database, DatabaseNode $node, ?DatabaseNode $without = null): NodeConfig
    {
        $nodes = $database->orderedNodesWithout($without);
        $primary = $nodes->first(fn (DatabaseNode $candidate): bool => $candidate->isPrimary()) ?? $nodes->firstOrFail();

        return new NodeConfig(
            clustered: $nodes->count() > 1,
            readOnly: $node->isReadOnly(),
            ip: $node->privateIp(),
            databaseId: $database->id,
            members: $nodes->toMembers(),
            settings: array_filter($database->settings->config, fn (string $value): bool => $value !== ''),
            secrets: Secrets::of($database->settings),
            archive: $database->pointInTimeRecovery === null ? null : ArchiveSettings::of($database->pointInTimeRecovery),
            primary: $primary->privateIp(),
        );
    }

    /**
     * @return list<Member>
     */
    public function membersOf(Database $database, ?DatabaseNode $without = null): array
    {
        return $database->orderedNodesWithout($without)->toMembers();
    }
}
