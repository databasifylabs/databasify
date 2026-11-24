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
    ) {}

    public function handle(Database $database, NewDatabaseNode $data): void
    {
        $database->ensureIdle();
        $database->ensureRoomFor($data->kind);
        $name = $data->kind === NodeKind::ReadOnly ? $data->name : null;
        $zone = $database->dnsZone;

        if ($zone !== null && $name !== null) {
            $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        }

        $data->server->ensureSized();
        $database->ensurePackagesRunOn($database->settings->packages, $data->server->architecture, $data->server->name);
        $node = $database->markBusyWithReadOnly(DatabaseStatus::Scaling, fn (Database $locked): DatabaseNode => $locked->addNode($data->server->id, $data->kind, $name));
        dispatch(new AddNodeJob($database, $node));
    }
}
