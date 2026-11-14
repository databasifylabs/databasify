<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Events\DatabaseAlertRaised;
use App\Exceptions\Databases\OperationStopped;
use App\Models\Database;
use App\Models\ServerLog;
use Throwable;

final readonly class EndFailedOperation
{
    public function __construct(private RefreshDatabaseStatus $refresh) {}

    public function handle(Database $database, string $operation, ?Throwable $exception, bool $markFailed = false): void
    {
        $database = $database->fresh();

        if ($database === null || ! $database->isBusy()) {
            return;
        }

        $database->serverLogs()->running()->lazyById()->each(fn (ServerLog $log) => $log->failBecauseOf($exception));

        if (! $exception instanceof OperationStopped) {
            event(new DatabaseAlertRaised($database, "{$database->name}: {$operation} failed", $exception?->getMessage() ?? 'The job stopped unexpectedly.'));
        }

        if ($markFailed) {
            $database->markFailed();

            return;
        }

        rescue(fn () => $this->refresh->handle($database));
        $database->markReady();
    }
}
