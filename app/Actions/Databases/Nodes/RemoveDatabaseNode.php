<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Jobs\Databases\RemoveDatabaseNodeJob;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RemoveDatabaseNode
{
    public function handle(DatabaseNode $node, bool $force = false): void
    {
        $database = $node->database;
        $database->ensureIdle();

        if ($node->isPrimary()) {
            throw ValidationException::withMessages(['node' => "{$node->server->name} is the primary, fail over first."]);
        }

        if (! $node->isReadOnly() && $database->memberCount() === 1) {
            throw ValidationException::withMessages(['node' => "{$node->server->name} is the only member left."]);
        }

        $database->startOperation("Remove {$node->displayName()}");
        dispatch(new RemoveDatabaseNodeJob($node, $force));
    }
}
