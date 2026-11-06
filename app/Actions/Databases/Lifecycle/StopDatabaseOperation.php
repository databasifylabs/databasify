<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class StopDatabaseOperation
{
    public function handle(Database $database): void
    {
        if (! $database->isBusy()) {
            throw ValidationException::withMessages(['database' => "{$database->name} is not running anything."]);
        }

        $database->requestStop();
    }
}
