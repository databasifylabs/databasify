<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Engines\Runtime\Runtime;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;

final readonly class UndoNodeJoin
{
    public function __construct(
        private Runtime $runtime,
        private ClearNodeDns $dns,
        private BuildNodeConfig $nodeConfig,
        private EngineTasks $tasks,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->tasks->stopRunning($database);
        $joiner = $database->nodes()->with('server')->find($node->id);

        if ($joiner === null) {
            return;
        }

        rescue(fn () => $this->runtime->remove($joiner, erase: true));
        rescue(fn () => $this->dns->handle($joiner));
        $joiner->delete();
        $database->forgetNodeCertificates($joiner);

        $remaining = $database->nodes()->get();

        if ($remaining->hasSole() && $database->facts()->seedClustersFirst) {
            rescue(fn () => $remaining->sole()->driver()->cluster()->disable());

            return;
        }

        $remaining->each(fn (DatabaseNode $peer) => rescue(fn () => $peer->driver()->nodes()->configure($this->nodeConfig->handle($database, $peer))));

        if ($remaining->hasMany()) {
            $members = $this->nodeConfig->membersOf($database);
            $remaining->each(fn (DatabaseNode $peer) => rescue(fn () => $peer->driver()->cluster()->membership($members)));
        }
    }
}
