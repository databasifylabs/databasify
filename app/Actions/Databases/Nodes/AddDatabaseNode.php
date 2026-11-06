<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\DTOs\Databases\NewDatabaseNode;
use App\DTOs\Dns\HostPair;
use App\Enums\Databases\NodeKind;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\DB;

final readonly class AddDatabaseNode
{
    public function __construct(private EnsureHostnamesFree $free) {}

    public function handle(Database $database, NewDatabaseNode $data): void
    {
        $database->ensureIdle();
        $database->ensureRoomFor($data->kind);
        $name = $data->kind === NodeKind::ReadOnly ? $data->name : null;
        $this->ensureNamesFree($database, $name);

        $node = DB::transaction(function () use ($database, $data, $name): DatabaseNode {
            $database->startOperation("Add {$data->kind->label()} node ".($name ?? $data->server->name));

            return $database->addNode($data->server->id, $data->kind, $name);
        });
        dispatch(new AddDatabaseNodeJob($database, $node));
    }

    private function ensureNamesFree(Database $database, ?string $name): void
    {
        $zone = $database->dnsZone;

        if ($zone !== null && $name !== null) {
            $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        }
    }
}
