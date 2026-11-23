<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Models\Database;
use App\Support\Databases\ExtensionCache;
use App\Support\Databases\ExtensionChanges;

final readonly class ApplySchemaExtensionUpdate
{
    public function __construct(
        private ExtensionCache $cache,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, string $schema, string $name): void
    {
        $database->driver()->extensions()->update($schema, $name);
        $this->cache->forget($database, $schema);
        $this->changes->end($database);
        $database->markReady();
    }
}
