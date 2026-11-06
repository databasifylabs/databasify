<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Jobs\Databases\SyncRecoveryFilesJob;
use App\Models\Database;

final readonly class QueueRecoveryFiles
{
    public function handle(Database $database, string $title): void
    {
        $database->startOperation($title);
        dispatch(new SyncRecoveryFilesJob($database));
    }
}
