<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Enums\Databases\DatabaseStatus;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class ApplyMaintenance
{
    public function __construct(
        private CheckDatabaseUpgrade $check,
        private UpgradeDatabase $upgrade,
    ) {}

    public function handle(Database $database): void
    {
        if ($database->status->isSettingUp() || $database->status === DatabaseStatus::Failed || ! $database->nodesWithServers()->isHealthy()) {
            return;
        }

        try {
            if ($this->check->handle($database) !== null) {
                $this->upgrade->handle($database);
            }
        } catch (ImageUnavailable|ValidationException) {
            return;
        }
    }
}
