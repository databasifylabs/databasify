<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\ServerLog;
use Throwable;

trait FailsNetworkLogs
{
    public function failed(?Throwable $exception): void
    {
        ServerLog::query()->running()->whereIn('name', $this->logNames())
            ->whereIn('server_id', $this->network->servers()->select('id'))->lazyById()
            ->each(fn (ServerLog $log) => $log->failBecauseOf($exception));
    }

    /**
     * @return list<string>
     */
    abstract protected function logNames(): array;
}
