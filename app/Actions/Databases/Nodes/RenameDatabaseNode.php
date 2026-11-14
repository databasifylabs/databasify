<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\DTOs\Dns\HostPair;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RedeployNodeJob;
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

        $node->ensureReadOnly();
        $database->ensureRunsSupportedSdk();
        $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        $node->markBusy(DatabaseStatus::Redeploying);
        rescue(fn () => $this->clear->handle($node), report: false);
        $node->rename($name);
        dispatch(new RedeployNodeJob($database, $node));
    }
}
