<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Enums\Databases\PasswordEncryption;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Validation\ValidationException;

final readonly class CreateDatabaseUser
{
    public function handle(Database $database, string $name, ?PasswordEncryption $encryption = null): DatabaseUser
    {
        if ($database->hasUser($name)) {
            throw ValidationException::withMessages(['name' => "{$database->name} already has a user named {$name}."]);
        }

        $password = new_password();
        $encryption = $database->passwordEncryptionFor($encryption);
        $database->engineAccounts()->createUser($name, $password, $encryption);

        return $database->users()->create(['name' => $name, 'password' => $password, 'password_encryption' => $encryption]);
    }
}
