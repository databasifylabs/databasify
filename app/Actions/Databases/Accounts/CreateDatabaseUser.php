<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\UserCreate;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Validation\ValidationException;

final readonly class CreateDatabaseUser
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(Database $database, string $name, ?string $encryption = null): DatabaseUser
    {
        $database->ensureRunsSupportedSdk();
        $database->ensureManagesAccounts();

        if ($database->hasUser($name)) {
            throw ValidationException::withMessages(['name' => "{$database->name} already has a user named {$name}."]);
        }

        $password = new_password();
        $encryption = $database->passwordEncryptionFor($encryption);
        $this->primary->handle($database, new UserCreate($name, $password, $database->writeGrants(), $encryption), "Create user {$name}");

        return $database->users()->create(['name' => $name, 'password' => $password, 'password_encryption' => $encryption]);
    }
}
