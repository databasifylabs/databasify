<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Engines\Data\InstalledExtension;
use App\Models\Database;
use App\Models\Schema;
use App\Support\Databases\ExtensionCache;

final readonly class CountExtensionUpdates
{
    public function __construct(
        private ReadSchemaExtensions $installed,
        private ExtensionCache $cache,
    ) {}

    public function handle(Database $database): int
    {
        return $this->cache->updateCount($database, fn (): int => $database->schemas()->get()
            ->sum(fn (Schema $schema): int => collect(rescue(fn (): array => $this->installed->handle($schema), [], report: false))
                ->filter(fn (InstalledExtension $extension): bool => $extension->hasUpdate())
                ->count()));
    }
}
