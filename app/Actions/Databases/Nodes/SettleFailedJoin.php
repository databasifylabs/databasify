<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\ReadNodeStatus;
use App\Actions\Databases\Lifecycle\RefreshNodeStatuses;
use App\DTOs\Databases\NodeStatus;
use App\Exceptions\Databases\WorkLeftUndone;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Messages\Sentence;
use Throwable;

final readonly class SettleFailedJoin
{
    public function __construct(
        private ReadNodeStatus $readStatus,
        private DetachNode $detach,
        private RefreshNodeStatuses $refreshNodes,
    ) {}

    public function handle(Database $database, DatabaseNode $node, Throwable $failure): void
    {
        $database->ignoringStop(fn () => $this->settle($database, $node, $failure));
    }

    private function settle(Database $database, DatabaseNode $node, Throwable $failure): void
    {
        $status = $this->readStatus->handle($database, $node, "{$node->server->name}: checking whether it joined");

        if (! $status->role->serves()) {
            $this->rollBack($database, $node, $failure);
        }

        $this->keep($database, $node, $status, $failure);
    }

    private function rollBack(Database $database, DatabaseNode $node, Throwable $failure): never
    {
        try {
            $this->detach->handle($database, $node, force: true);
        } catch (WorkLeftUndone $leftover) {
            throw new WorkLeftUndone(Sentence::of($failure->getMessage()).' '.$leftover->getMessage(), previous: $failure);
        } catch (Throwable $exception) {
            report($exception);
        }

        throw $failure;
    }

    private function keep(Database $database, DatabaseNode $node, NodeStatus $status, Throwable $failure): never
    {
        $node->recordStatus($status);
        $database->matchModeToMembers();
        $this->refreshNodes->handle($database, $database->nodesOtherThan($node));
        $message = "{$node->server->name} joined {$database->name} as {$status->role->value} and is kept, but ".Sentence::of($failure->getMessage())." Remove {$node->server->name} or redeploy {$database->name} to finish.";
        $node->server->logFailure("{$node->server->name}: finishing the join", $message, $database);

        throw new WorkLeftUndone($message, previous: $failure);
    }
}
