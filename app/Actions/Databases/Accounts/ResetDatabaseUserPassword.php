<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\UserSetPassword;
use App\Models\DatabaseUser;

final readonly class ResetDatabaseUserPassword
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(DatabaseUser $user): DatabaseUser
    {
        $user->database->ensureRunsSupportedSdk();
        $password = new_password();
        $this->primary->handle($user->database, new UserSetPassword($user->name, $password, $user->password_encryption), "Reset password for {$user->name}");
        $user->update(['password' => $password]);

        return $user;
    }
}
