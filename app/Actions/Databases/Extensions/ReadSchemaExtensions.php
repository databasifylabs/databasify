<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Engines\Data\InstalledExtension;
use App\Enums\Engines\Feature;
use App\Models\Schema;
use App\Support\Databases\ExtensionCache;

final readonly class ReadSchemaExtensions
{
    public function __construct(private ExtensionCache $cache) {}

    /**
     * @return list<InstalledExtension>
     */
    public function handle(Schema $schema): array
    {
        if (! $schema->database->offers(Feature::Extensions)) {
            return [];
        }

        return $this->cache->installed($schema->database, $schema->name, fn (): array => $schema->database->driver()->extensions()->list($schema->name));
    }
}
