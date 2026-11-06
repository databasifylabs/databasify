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
        if ($database->status !== DatabaseStatus::Ready) {
            return;
        }

        try {
            $this->upgradeIfAvailable($database);
        } catch (ImageUnavailable|ValidationException) {
            return;
        }
    }

    private function upgradeIfAvailable(Database $database): void
    {
        if ($this->check->handle($database) === null) {
            return;
        }

        $this->upgrade->handle($database);
    }
}
