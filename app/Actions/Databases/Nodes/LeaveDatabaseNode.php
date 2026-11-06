<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\DatabaseNode;
use RuntimeException;

final readonly class LeaveDatabaseNode
{
    public function __construct(
        private DetachNode $detach,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(DatabaseNode $node, bool $force = false): void
    {
        $database = $node->database;

        $this->refresh->handle($database);

        if ($node->refresh()->isPrimary()) {
            throw new RuntimeException("{$node->server->name} became the primary since the request, fail over first.");
        }

        try {
            $this->detach->handle($database, $node, $force);
        } finally {
            $database->matchModeToMembers();
            $this->refresh->handle($database);
        }
    }
}
