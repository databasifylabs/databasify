<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class FailoverDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureNotBusy();

        if (! $database->hasStandby()) {
            throw ValidationException::withMessages(['database' => 'Failover needs at least one standby node.']);
        }

        $database->markBusy(DatabaseStatus::Recovering);
        dispatch(new FailoverDatabaseJob($database));
    }
}
