<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Flows\StartFlow;
use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewDatabase;
use App\Engines\EngineSecrets;
use App\Engines\Flows\CreateDatabaseFlow;
use App\Engines\Flows\FlowContext;
use App\Enums\Databases\DatabaseStatus;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateDatabase
{
    public function __construct(
        private ImageResolver $newestImage,
        private StartFlow $startFlow,
        private CreateDatabaseFlow $createFlow,
    ) {}

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

        $this->startFlow->handle($database, $this->createFlow, new FlowContext(array_values(array_filter($database->nodes()->pluck('id')->all(), is_string(...)))));

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
            secrets: $data->secrets ?? [...$engine->generateSecrets(), ...EngineSecrets::generate()],
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
