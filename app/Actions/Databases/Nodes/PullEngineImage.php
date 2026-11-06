<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\DTOs\Databases\EngineImage;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class PullEngineImage
{
    public function handle(Database $database, DatabaseNode $node, EngineImage $image): void
    {
        $engine = $database->provider();

        $node->ssh()->exec(
            'ssh.databases.nodes.pull',
            [...$engine->scriptData()->for($node), 'image' => $image->reference()],
            "{$node->server->name}: pulling {$engine->label()} {$image->release}",
        );
    }
}
