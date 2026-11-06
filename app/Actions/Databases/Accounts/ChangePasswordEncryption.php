<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Enums\Databases\PasswordEncryption;
use App\Models\DatabaseUser;
use Illuminate\Validation\ValidationException;

final readonly class ChangePasswordEncryption
{
    public function handle(DatabaseUser $user, PasswordEncryption $encryption): DatabaseUser
    {
        if (! $user->hasEncryptionChoice()) {
            throw ValidationException::withMessages(['password_encryption' => "{$user->database->provider()->label()} has no password encryption to choose."]);
        }

        if ($user->password_encryption === $encryption) {
            return $user;
        }

        $user->database->engineAccounts()->updatePasswordEncryption($user->name, $user->password, $encryption);
        $user->update(['password_encryption' => $encryption]);

        return $user;
    }
}
