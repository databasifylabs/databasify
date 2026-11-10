<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\Database;

final readonly class DeleteDatabase
{
    public function handle(Database $database, bool $eraseData = false, bool $force = false, bool $deleteBackups = false): void
    {
        $database->ensureIdleOnAnyWrapper();
        $database->startOperation($eraseData ? 'Delete database and erase data' : 'Delete database');
        dispatch(new DeleteDatabaseJob($database, $eraseData, $force, $deleteBackups));
    }
}
