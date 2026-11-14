<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use Closure;

final readonly class RunsDatabaseOperation
{
    /**
     * @param  Closure(Database): void  $abandon
     */
    public function __construct(
        private Database $database,
        private DatabaseStatus $status,
        private Closure $abandon,
    ) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $database = $this->database->fresh();

        if ($database === null || $database->status !== $this->status) {
            return null;
        }

        if ($database->isStopRequested()) {
            ($this->abandon)($database);

            return null;
        }

        $result = $next($job);
        $database->markReady();

        return $result;
    }
}
