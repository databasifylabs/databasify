<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Jobs\Databases\FailoverDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class FailoverDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();

        if (! $database->hasStandby()) {
            throw ValidationException::withMessages(['database' => 'Failover needs at least one standby node.']);
        }

        $database->startOperation('Fail over');
        dispatch(new FailoverDatabaseJob($database));
    }
}
