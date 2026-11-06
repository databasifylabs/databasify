<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewDatabase;
use App\Enums\Databases\DatabaseStatus;
use App\Exceptions\Images\ImageUnavailable;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateDatabase
{
    public function __construct(private ImageResolver $newestImage) {}

    public function handle(NewDatabase $data): Database
    {
        $draft = new Database(['engine' => $data->engine]);
        $engine = $draft->provider();
        $line = $data->versionLine ?? $engine->versions()->firstWhere('isDefault', true)->value ?? '';
        $image = $data->image ?? $this->newestImageOf($draft, $line);

        $database = DB::transaction(function () use ($data, $engine, $line, $image): Database {
            $database = Database::query()->create(array_merge([
                'name' => $data->name,
                'engine' => $data->engine,
                'version_line' => $line,
                'mode' => $data->mode(),
                'status' => DatabaseStatus::Creating,
                'settings' => $this->settingsFor($data, $engine),
            ], $image->toRow()));

            collect($data->memberServerIds())->each(fn (string $serverId) => $database->addNode($serverId));
            $this->recordAccountsOf($database, $data);

            return $database;
        });

        $database->startOperation("Create {$database->provider()->label()} database");
        dispatch(new BootstrapDatabaseJob($database));

        return $database;
    }

    private function recordAccountsOf(Database $database, NewDatabase $data): void
    {
        if ($data->accountsFrom !== null) {
            $database->copyAccountsFrom($data->accountsFrom);

            return;
        }

        $database->recordDefaultAccounts();
    }

    private function settingsFor(NewDatabase $data, DatabaseDriver $engine): DatabaseSettings
    {
        return new DatabaseSettings(
            secrets: $data->secrets ?? $engine->generateSecrets(),
            config: $data->config,
            persistence: $data->persistence,
            restore: $data->restore,
        );
    }

    private function newestImageOf(Database $database, string $line): EngineImage
    {
        try {
            return $this->newestImage->newest($database, $line);
        } catch (ImageUnavailable $unavailable) {
            throw ValidationException::withMessages(['version_line' => "{$unavailable->getMessage()} Try again in a moment."]);
        }
    }
}
