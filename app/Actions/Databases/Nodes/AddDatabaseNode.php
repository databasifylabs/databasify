<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\DTOs\Databases\NewDatabaseNode;
use App\DTOs\Dns\HostPair;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Jobs\Databases\AddNodeJob;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class AddDatabaseNode
{
    public function __construct(
        private EnsureHostnamesFree $free,
        private EnsureNodesSized $sized,
    ) {}

    public function handle(Database $database, NewDatabaseNode $data): void
    {
        $database->ensureIdle();
        $database->ensureRoomFor($data->kind);
        $name = $data->kind === NodeKind::ReadOnly ? $data->name : null;
        $this->ensureNamesFree($database, $name);

        $node = $database->markBusyWith(DatabaseStatus::Scaling, function (Database $locked) use ($data, $name): DatabaseNode {
            $node = $locked->addNode($data->server->id, $data->kind, $name);
            $this->sized->handle($locked);

            return $node;
        });
        dispatch(new AddNodeJob($database, $node));
    }

    private function ensureNamesFree(Database $database, ?string $name): void
    {
        $zone = $database->dnsZone;

        if ($zone === null || $name === null) {
            return;
        }

        $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
    }
}
