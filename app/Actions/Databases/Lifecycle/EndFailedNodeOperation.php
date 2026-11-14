<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Events\DatabaseAlertRaised;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use App\Services\Databases\EngineTasks;
use Throwable;

final readonly class EndFailedNodeOperation
{
    public function __construct(private EngineTasks $tasks) {}

    public function handle(DatabaseNode $node, string $operation, ?Throwable $exception): void
    {
        $node = $node->fresh();

        if ($node === null || ! $node->isBusy()) {
            return;
        }

        $this->tasks->stopRunning($node);
        $node->server->serverLogs()->running()->where('database_id', $node->database_id)->lazyById()->each(fn (ServerLog $log) => $log->failBecauseOf($exception));

        event(new DatabaseAlertRaised($node->database, "{$node->database->name}: {$operation} on {$node->displayName()} failed", $exception?->getMessage() ?? 'The job stopped unexpectedly.'));

        $node->markReady();
    }
}
