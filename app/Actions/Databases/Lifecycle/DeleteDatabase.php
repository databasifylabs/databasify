<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\Database;

final readonly class DeleteDatabase
{
    public function handle(Database $database, bool $eraseData = false, bool $force = false, bool $deleteBackups = false): void
    {
        $database->ensureNotBusy();
        $setupFailed = $database->markBusyWithReadOnly(DatabaseStatus::Deleting, fn (Database $locked): bool => $locked->status === DatabaseStatus::Failed);
        dispatch(new DeleteDatabaseJob($database, $eraseData, $force, $deleteBackups, $setupFailed));
    }
}
