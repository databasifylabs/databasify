<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Models\DatabaseUser;

final readonly class ResetDatabaseUserPassword
{
    public function handle(DatabaseUser $user): DatabaseUser
    {
        $user->database->ensureRunsSupportedSdk();
        $password = new_password();
        $user->database->driver()->accounts()->setPassword($user->name, $password, $user->password_encryption);
        $user->update(['password' => $password]);

        return $user;
    }
}
