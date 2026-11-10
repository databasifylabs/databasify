<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\DatabaseNode;

final readonly class InstallHostBinary
{
    public function handle(DatabaseNode $node): void
    {
        $node->ssh()->exec(
            'ssh.databases.nodes.install-host-binary',
            ['image' => $node->database->image()->reference()],
            "{$node->server->name}: installing the databasify command",
        );
    }
}
