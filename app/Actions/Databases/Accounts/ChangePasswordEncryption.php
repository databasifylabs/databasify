<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\UserSetPassword;
use App\Models\DatabaseUser;
use Illuminate\Validation\ValidationException;

final readonly class ChangePasswordEncryption
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(DatabaseUser $user, string $encryption): DatabaseUser
    {
        $user->database->ensureRunsSupportedSdk();
        if (! $user->hasEncryptionChoice()) {
            throw ValidationException::withMessages(['password_encryption' => "{$user->database->engine()->label} has no password encryption to choose."]);
        }

        if ($user->password_encryption === $encryption) {
            return $user;
        }

        $this->primary->handle($user->database, new UserSetPassword($user->name, $user->password, $encryption), "Change password encryption for {$user->name}");
        $user->update(['password_encryption' => $encryption]);

        return $user;
    }
}
