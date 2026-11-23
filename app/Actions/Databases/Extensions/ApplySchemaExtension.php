<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Actions\Databases\Configuration\RollOutSettings;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeSettingsJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Databases\ExtensionCache;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Validation\ValidationException;

final readonly class ApplySchemaExtension
{
    public function __construct(
        private RollOutSettings $settings,
        private ExtensionCache $cache,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, string $schema, string $name, bool $rollOut, string $run): void
    {
        if ($rollOut) {
            $this->settings->handle($database, false, $run);
            $database->markBusy(DatabaseStatus::Updating);
            $database->nodesWithServers()->readOnly()->each(function (DatabaseNode $node): void {
                try {
                    $node->markBusy(DatabaseStatus::Updating);
                } catch (ValidationException) {
                    return;
                }

                dispatch(new ApplyNodeSettingsJob($node));
            });
        }

        $database->driver()->extensions()->enable($schema, $name);
        $this->cache->forget($database, $schema);
        $this->changes->end($database);
        $database->markReady();
    }
}
