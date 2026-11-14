<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Models\Database;
use App\Models\Schema;
use Illuminate\Validation\ValidationException;

final readonly class CreateSchema
{
    public function handle(Database $database, string $name): Schema
    {
        $database->ensureRunsSupportedSdk();
        $database->ensureManagesAccounts();

        if ($database->hasSchema($name)) {
            throw ValidationException::withMessages(['name' => "{$database->name} already has a database named {$name}."]);
        }

        $database->driver()->accounts()->createDatabase($name);
        $schema = $database->schemas()->create(['name' => $name]);
        $grants = $database->writeGrants();

        foreach ($database->userNames() as $user) {
            $database->driver()->accounts()->grant($user, $grants, false);
        }

        return $schema;
    }
}
