<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\UserDrop;
use App\Models\DatabaseUser;

final readonly class DeleteDatabaseUser
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(DatabaseUser $user): void
    {
        $user->ensureDeletable();
        $this->primary->handle($user->database, new UserDrop($user->name), "Delete user {$user->name}");
        $user->delete();
    }
}
