<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\Database;

final readonly class BackUpNow
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();
        dispatch(new RefreshRecoveryStatusJob($database->recoveryOrFail(), backUpNow: true));
    }
}
