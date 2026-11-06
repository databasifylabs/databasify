<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Models\Schema;

final readonly class DeleteSchema
{
    public function handle(Schema $schema): void
    {
        $schema->ensureDeletable();
        $schema->database->engineAccounts()->deleteDatabase($schema->name);
        $schema->delete();
    }
}
