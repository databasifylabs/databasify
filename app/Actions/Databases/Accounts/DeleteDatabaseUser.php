<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Models\DatabaseUser;

final readonly class DeleteDatabaseUser
{
    public function handle(DatabaseUser $user): void
    {
        $user->ensureDeletable();
        $user->database->engineAccounts()->deleteUser($user->name);
        $user->delete();
    }
}
