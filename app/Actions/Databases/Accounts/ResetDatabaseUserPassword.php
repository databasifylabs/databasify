<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Models\DatabaseUser;

final readonly class ResetDatabaseUserPassword
{
    public function handle(DatabaseUser $user): DatabaseUser
    {
        $password = new_password();
        $user->database->engineAccounts()->updatePassword($user->name, $password, $user->password_encryption);
        $user->update(['password' => $password]);

        return $user;
    }
}
