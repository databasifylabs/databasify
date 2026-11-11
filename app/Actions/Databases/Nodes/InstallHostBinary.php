<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\DatabaseNode;
use App\Services\Images\HostBinaryResolver;

final readonly class InstallHostBinary
{
    public function __construct(private HostBinaryResolver $hostBinary) {}

    public function handle(DatabaseNode $node): void
    {
        $node->ssh()->exec(
            'ssh.databases.nodes.install-host-binary',
            ['image' => $this->hostBinary->pinned()->pinnedReference()],
            "{$node->server->name}: installing the databasify command",
        );
    }
}
