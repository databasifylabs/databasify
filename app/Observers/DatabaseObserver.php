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
        );
        $database->serverLogs()->update(['database_id' => null]);
    }
}
