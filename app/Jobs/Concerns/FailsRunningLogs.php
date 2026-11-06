<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\ServerLog;
use Throwable;

trait FailsRunningLogs
{
    public function failed(?Throwable $exception): void
    {
        $this->server->serverLogs()->running()->whereIn('name', $this->logNames())->lazyById()
            ->each(fn (ServerLog $log) => $log->failBecauseOf($exception));
    }

    /**
     * @return list<string>
     */
    abstract protected function logNames(): array;
}
