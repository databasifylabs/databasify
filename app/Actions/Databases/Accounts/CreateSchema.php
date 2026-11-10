<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\UserGrant;
use App\Models\Database;
use App\Models\Schema;
use Illuminate\Validation\ValidationException;

final readonly class CreateSchema
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(Database $database, string $name): Schema
    {
        $database->ensureManagesAccounts();

        if ($database->hasSchema($name)) {
            throw ValidationException::withMessages(['name' => "{$database->name} already has a database named {$name}."]);
        }

        $this->primary->handle($database, new DatabaseCreate($name), "Create database {$name}");
        $schema = $database->schemas()->create(['name' => $name]);
        $grants = $database->writeGrants();

        foreach ($database->userNames() as $user) {
            $this->primary->handle($database, new UserGrant($user, $grants), "Grant {$user} access to {$name}");
        }

        return $schema;
    }
}
