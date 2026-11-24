<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\Engines\Data\InstalledExtension;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ExtensionChangeAction;
use App\Jobs\Databases\DisableSchemaExtensionJob;
use App\Models\Schema;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Validation\ValidationException;

final readonly class DisableSchemaExtension
{
    public function __construct(
        private ReadSchemaExtensions $installed,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Schema $schema, string $name): void
    {
        $schema->database->ensureIdle();

        if (! collect($this->installed->handle($schema))->contains(fn (InstalledExtension $installed): bool => $installed->name === $name)) {
            throw ValidationException::withMessages(['name' => "{$name} isn't enabled in {$schema->name}."]);
        }

        $schema->database->markBusy(DatabaseStatus::Updating);
        $this->changes->start($schema->database, new ExtensionChange($schema->name, $name, ExtensionChangeAction::Removing));
        dispatch(new DisableSchemaExtensionJob($schema->database, $schema->name, $name));
    }
}
