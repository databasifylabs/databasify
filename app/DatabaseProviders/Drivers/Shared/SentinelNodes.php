<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DatabaseProviders\Groups\EngineNodes;
use App\Models\DatabaseNode;

final readonly class SentinelNodes extends EngineNodes
{
    public function reset(DatabaseNode $node, int $peers): void
    {
        if ($peers === 0) {
            return;
        }

        $this->runStep($node, 'lifecycle.reset', [...$this->scriptData()->for(), 'peers' => $peers, 'hosts' => $this->database->hosts()], "{$node->server->name}: resetting cluster peers");
    }

    public function finishBootstrap(DatabaseNode $seed): void {}
}
