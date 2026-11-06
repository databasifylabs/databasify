<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Models\Database;

final readonly class DisablePointInTimeRecovery
{
    public function __construct(private RedeployDatabase $redeploy) {}

    public function handle(Database $database, bool $deleteBackups = false): void
    {
        $database->ensureIdle();
        $archive = $database->disableRecovery();

        $then = $deleteBackups ? [new DeleteRecoveryBackupsJob($database, $archive)] : [];

        $this->redeploy->handle($database, 'Turn off point-in-time recovery', then: $then);
    }
}
