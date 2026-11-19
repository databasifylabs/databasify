<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\Database;

final readonly class DatabaseObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(Database $database): void
    {
        $this->children->handle(
            $database->migrations(),
            $database->nodes(),
            $database->users(),
            $database->schemas(),
            $database->trustedSources(),
            $database->connectionPools(),
            $database->pointInTimeRecovery(),
            $database->fileBackupDestinations(),
        );
        $database->serverLogs()->update(['database_id' => null]);
    }

    public function updated(Database $database): void
    {
        if ($database->wasChanged('project_id')) {
            $database->fileBackupDestinations()->update(['project_id' => $database->project_id]);
        }
    }
}
