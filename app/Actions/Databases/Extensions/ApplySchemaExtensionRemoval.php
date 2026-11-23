<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Engines\Data\AvailableExtension;
use App\Models\Database;
use App\Support\Databases\ExtensionCache;
use App\Support\Databases\ExtensionChanges;

final readonly class ApplySchemaExtensionRemoval
{
    public function __construct(
        private ExtensionCache $cache,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, string $schema, string $name): void
    {
        $extensions = $database->driver()->extensions();
        $extensions->disable($schema, $name);
        $this->cache->forget($database, $schema);
        $this->changes->end($database);
        $available = collect($extensions->available());
        $library = $available->first(fn (AvailableExtension $extension): bool => $extension->entry->name === $name)?->entry->library;
        $libraryUnused = $library !== null && $available
            ->filter(fn (AvailableExtension $extension): bool => $extension->entry->library === $library)
            ->every(fn (AvailableExtension $extension): bool => $extensions->usedIn($extension->entry->name) === []);

        if ($libraryUnused) {
            $database->update(['settings' => $database->settings->withExtensions($database->settings->extensions->without($library))]);
        }

        $database->markReady();
    }
}
