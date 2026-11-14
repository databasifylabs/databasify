<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RetryDatabaseSetup
{
    public function handle(Database $database): void
    {
        DB::transaction(function () use ($database): void {
            $database->refreshForUpdate();

            if ($database->status !== DatabaseStatus::Failed) {
                throw ValidationException::withMessages(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
            }

            $database->update(['status' => DatabaseStatus::Pending]);
        });

        dispatch(new CreateDatabaseJob($database));
    }
}
