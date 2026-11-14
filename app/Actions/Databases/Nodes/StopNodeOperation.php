<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\EndFailedNodeOperation;
use App\Exceptions\Databases\OperationStopped;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class StopNodeOperation
{
    public function __construct(private EndFailedNodeOperation $end) {}

    public function handle(DatabaseNode $node): void
    {
        if (! $node->isBusy()) {
            throw ValidationException::withMessages(['database' => "{$node->displayName()} is not running anything."]);
        }

        if ($node->isClaimedByDatabase()) {
            throw ValidationException::withMessages(['database' => "{$node->displayName()} is part of {$node->database->name}'s {$node->database->status->value}, stop it from {$node->database->name}."]);
        }

        if ($node->isStopRequested()) {
            $this->end->handle($node, 'Stop', new OperationStopped('Released by you after the operation did not stop.'));

            return;
        }

        $node->requestStop();
    }
}
