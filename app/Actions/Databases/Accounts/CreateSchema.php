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
        if ($database->hasSchema($name)) {
            throw ValidationException::withMessages(['name' => "{$database->name} already has a database named {$name}."]);
        }

        $database->engineAccounts()->createDatabase($name);

        return $database->schemas()->create(['name' => $name]);
    }
}
