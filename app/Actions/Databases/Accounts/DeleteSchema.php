<?php

declare(strict_types=1);

namespace App\Actions\Databases\Accounts;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\DatabaseDrop;
use App\Models\Schema;

final readonly class DeleteSchema
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(Schema $schema): void
    {
        $schema->ensureDeletable();
        $this->primary->handle($schema->database, new DatabaseDrop($schema->name), "Delete database {$schema->name}");
        $schema->delete();
    }
}
