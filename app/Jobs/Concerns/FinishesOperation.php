<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\ReleasesDatabaseOperation;
use App\Models\Database;
use App\Models\ServerLog;
use Throwable;

trait FinishesOperation
{
    /**
     * @return list<ReleasesDatabaseOperation>
     */
    public function middleware(): array
    {
        return [new ReleasesDatabaseOperation($this->operatedDatabase())];
    }

    public function failed(?Throwable $exception): void
    {
        $database = $this->operatedDatabase()->fresh();

        if ($database === null) {
            return;
        }

        $database->serverLogs()->running()->lazyById()->each(fn (ServerLog $log) => $log->failBecauseOf($exception));
        $database->finishOperation();
    }

    protected function operatedDatabase(): Database
    {
        return $this->database;
    }
}
