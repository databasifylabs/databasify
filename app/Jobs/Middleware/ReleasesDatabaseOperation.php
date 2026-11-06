<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Models\Database;
use Closure;

final readonly class ReleasesDatabaseOperation
{
    public function __construct(private Database $database) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } finally {
            $this->database->finishOperation();
        }
    }
}
