<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class RetryDatabaseSetup
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();

        if (! $database->hasFailed()) {
            throw ValidationException::withMessages(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
        }

        $database->startOperation("Retry {$database->provider()->label()} setup");
        $database->markCreating();
        dispatch(new BootstrapDatabaseJob($database, clearFirst: true));
    }
}
