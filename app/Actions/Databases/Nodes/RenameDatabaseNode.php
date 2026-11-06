<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\DTOs\Dns\HostPair;
use App\Jobs\Databases\RedeployDatabaseNodeJob;
use App\Models\DatabaseNode;

final readonly class RenameDatabaseNode
{
    public function __construct(
        private EnsureHostnamesFree $free,
        private ClearNodeDns $clear,
    ) {}

    public function handle(DatabaseNode $node, string $name): void
    {
        $database = $node->database;
        $zone = $database->dnsZone;

        if ($zone === null) {
            $node->update(['name' => $name]);

            return;
        }

        $database->ensureIdle();
        $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        rescue(fn () => $this->clear->handle($node), report: false);
        $node->rename($name);
        $database->startOperation("Serve {$name} on ".$zone->hostFor($name));
        dispatch(new RedeployDatabaseNodeJob($database, $node));
    }
}
