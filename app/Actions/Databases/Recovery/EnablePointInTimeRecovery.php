<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\DTOs\Recovery\RecoverySettings;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\Database;

final readonly class EnablePointInTimeRecovery
{
    public function __construct(private RedeployDatabase $redeploy) {}

    public function handle(Database $database, RecoverySettings $settings): void
    {
        $database->ensureIdle();
        $recovery = $database->enableRecovery($settings);

        $this->redeploy->handle($database, 'Turn on point-in-time recovery', then: [new RefreshRecoveryStatusJob($recovery, backUpNow: true)]);
    }
}
