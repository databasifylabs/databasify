<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\Persistence;
use App\Jobs\Databases\ApplyDatabaseConfigJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePersistence
{
    private const string MEMORY_LIMIT_KEY = 'maxmemory';

    public function handle(Database $database, Persistence $persistence): void
    {
        $database->ensureIdle();

        $engine = $database->provider();

        if (! $engine->supportsPersistence()) {
            throw ValidationException::withMessages(['persistence' => "{$engine->label()} always keeps its data on disk."]);
        }

        $database->updatePersistence($persistence);
        $database->startOperation($persistence === Persistence::Off ? 'Turn off persistence' : 'Turn on snapshots');
        dispatch(new ApplyDatabaseConfigJob($database, [self::MEMORY_LIMIT_KEY]));
    }
}
