<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\Engines\Data\AvailableExtension;
use App\Engines\Data\InstalledExtension;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ExtensionChangeAction;
use App\Enums\Engines\Feature;
use App\Jobs\Databases\EnableSchemaExtensionJob;
use App\Models\Database;
use App\Models\Schema;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Validation\ValidationException;

final readonly class EnableSchemaExtension
{
    public function __construct(
        private ReadAvailableExtensions $available,
        private ReadSchemaExtensions $installed,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Schema $schema, string $name): void
    {
        $database = $schema->database;
        $database->ensureIdle();

        if (! $database->offers(Feature::Extensions)) {
            throw ValidationException::withMessages(['name' => "{$database->engine()->label} databases have no extensions."]);
        }

        $extension = collect($this->available->handle($database))->first(fn (AvailableExtension $available): bool => $available->entry->name === $name)
            ?? throw ValidationException::withMessages(['name' => "{$name} isn't available on this database."]);

        if (collect($this->installed->handle($schema))->contains(fn (InstalledExtension $installed): bool => $installed->name === $name)) {
            throw ValidationException::withMessages(['name' => "{$name} is already enabled in {$schema->name}."]);
        }

        $singleSchema = $extension->entry->singleSchema;
        $elsewhere = $singleSchema ? collect($database->driver()->extensions()->usedIn($name))->first(fn (string $used): bool => $used !== $schema->name) : null;

        if ($elsewhere !== null) {
            throw ValidationException::withMessages(['name' => "{$name} is already enabled in {$elsewhere}."]);
        }

        $library = $extension->entry->library;

        $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked): ?bool => $library === null ? null : $locked->update([
            'settings' => $locked->settings->withExtensions($locked->settings->extensions->with($library, $singleSchema ? $schema->name : null)),
        ]));
        $this->changes->start($database, new ExtensionChange($schema->name, $name, ExtensionChangeAction::Adding));
        dispatch(new EnableSchemaExtensionJob($database, $schema->name, $name, $library !== null));
    }
}
