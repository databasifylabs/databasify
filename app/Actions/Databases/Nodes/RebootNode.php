<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RebootNodeJob;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RebootNode
{
    public function handle(DatabaseNode $node): void
    {
        $node->ensureReadOnly();

        if (! $node->server->isReady()) {
            throw ValidationException::withMessages(['database' => "{$node->server->name} is not ready yet."]);
        }

        $node->markBusy(DatabaseStatus::Rebooting);
        dispatch(new RebootNodeJob($node));
    }
}
