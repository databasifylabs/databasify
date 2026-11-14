<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Databases\OperationStopped;
use App\Jobs\Middleware\RunsDatabaseOperation;
use App\Models\Database;
use App\Models\ServerLog;
use Throwable;

trait FinishesOperation
{
    /**
     * @return list<RunsDatabaseOperation>
     */
    public function middleware(): array
    {
        return [new RunsDatabaseOperation($this->operatedDatabase(), $this->operatingStatus(), $this->abandon(...))];
    }

    public function failed(?Throwable $exception): void
    {
        $database = $this->operatedDatabase()->fresh();

        if ($database === null || ! $this->stillRunsOn($database)) {
            return;
        }

        $database->serverLogs()->running()->lazyById()->each(fn (ServerLog $log) => $log->failBecauseOf($exception));

        if (! $exception instanceof OperationStopped) {
            event(new DatabaseAlertRaised($database, "{$database->name}: {$this->operationName()} failed", $exception?->getMessage() ?? 'The job stopped unexpectedly.'));
        }

        $this->endFailed($database);
    }

    protected function abandon(Database $database): void
    {
        $database->abandonOperation();
    }

    private function stillRunsOn(Database $database): bool
    {
        $status = $this->operatingStatus();

        return $database->status === $status || ($status->isSettingUp() && $database->status->isSettingUp());
    }

    protected function endFailed(Database $database): void
    {
        rescue(fn () => resolve(RefreshDatabaseStatus::class)->handle($database));
        $database->markReady();
    }

    protected function operatedDatabase(): Database
    {
        return $this->database;
    }

    abstract protected function operatingStatus(): DatabaseStatus;

    abstract protected function operationName(): string;
}
