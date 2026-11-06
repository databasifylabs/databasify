<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ApplyNodeFirewall
{
    /**
     * @param  array<int, string>  $sources
     */
    public function handle(Database $database, DatabaseNode $node, array $sources): void
    {
        $node->ssh()->exec(
            'ssh.databases.network.trusted-sources',
            $this->rulesFor($database, $node, $sources),
            "{$node->server->name}: applying trusted sources",
        );
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<string, mixed>
     */
    private function rulesFor(Database $database, DatabaseNode $node, array $sources): array
    {
        $ports = $database->provider()->ports();

        return [
            'table' => $database->firewallTable(),
            'remove' => false,
            'ports' => array_filter([$ports->client, $ports->pool], is_int(...)),
            'peerPorts' => $ports->peers,
            'peers' => $database->peerAddresses(),
            'sources' => array_values(array_unique($sources)),
            'vpcSubnet' => $node->server->network?->cidr,
        ];
    }
}
