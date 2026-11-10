<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Database;
use App\Models\ServerLog;

final readonly class ReleaseAbandonedWork
{
    private const int ABANDONED_AFTER_SECONDS = 28800;

    public function handle(): void
    {
        $cutoff = now()->subSeconds(self::ABANDONED_AFTER_SECONDS);

        ServerLog::query()->running()->startedBefore($cutoff)->lazyById()
            ->each(fn (ServerLog $log) => $log->fail('the worker stopped before this finished'));
        Database::query()->operationStartedBefore($cutoff)->withoutRunningFlow()->lazyById()
            ->each(fn (Database $database) => $database->finishOperation());
    }
}
