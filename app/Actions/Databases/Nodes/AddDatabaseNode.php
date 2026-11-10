<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\Actions\Databases\Flows\StartFlow;
use App\DTOs\Databases\NewDatabaseNode;
use App\DTOs\Dns\HostPair;
use App\Engines\Flows\AddNodeFlow;
use App\Engines\Flows\FlowContext;
use App\Enums\Databases\NodeKind;
use App\Models\Database;

final readonly class AddDatabaseNode
{
    public function __construct(
        private EnsureHostnamesFree $free,
        private StartFlow $startFlow,
        private AddNodeFlow $flow,
        private EnsureNodesSized $sized,
    ) {}

    public function handle(Database $database, NewDatabaseNode $data): void
    {
        $database->ensureIdle();
        $database->ensureRoomFor($data->kind);
        $name = $data->kind === NodeKind::ReadOnly ? $data->name : null;
        $this->ensureNamesFree($database, $name);

        $this->startFlow->handle($database, $this->flow, function () use ($database, $data, $name): FlowContext {
            $node = $database->addNode($data->server->id, $data->kind, $name);
            $this->sized->handle($database);

            return new FlowContext(array_values(array_filter($database->nodes()->pluck('id')->all(), is_string(...))), $node->id);
        });
    }

    private function ensureNamesFree(Database $database, ?string $name): void
    {
        $zone = $database->dnsZone;

        if ($zone !== null && $name !== null) {
            $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        }
    }
}
