<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\NewDatabase;
use App\Engines\EngineSecrets;
use App\Enums\Databases\DatabaseStatus;
use App\Exceptions\Images\ImageUnavailable;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Images\ImageResolver;
use App\Support\Databases\DefaultAccount;
use App\Support\Engines\SdkRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateDatabase
{
    public function __construct(
        private ImageResolver $newestImage,
    ) {}

    public function handle(NewDatabase $data): Database
    {
        $draft = new Database(['engine' => $data->engine]);
        $engine = $draft->engine();
        $line = $data->versionLine ?? $engine->defaultLine;

        try {
            $image = SdkRange::supported($engine->label, $data->image ?? $this->newestImage->newest($draft, $line));
        } catch (ImageUnavailable $unavailable) {
            throw ValidationException::withMessages(['version_line' => $unavailable->refused !== null ? $unavailable->getMessage() : "{$unavailable->getMessage()} Try again in a moment."]);
        }

        $settings = new DatabaseSettings(
            secrets: $data->secrets ?? [...($engine->laravelConnection === null ? [] : DefaultAccount::secrets()), ...EngineSecrets::generate()],
            config: $data->config,
            persistence: $data->persistence,
            restore: $data->restore,
            extensions: $data->extensions,
            packages: $data->packages,
        );

        $database = DB::transaction(function () use ($data, $line, $image, $settings): Database {
            $database = Database::query()->create([
                'name' => $data->name,
                'engine' => $data->engine,
                'version_line' => $line,
                'mode' => $data->mode(),
                'status' => DatabaseStatus::Pending,
                'image' => $image,
                'settings' => $settings,
            ]);

            collect($data->memberServerIds())->each(fn (string $serverId) => $database->addNode($serverId));
            $database->nodesWithServers()->each(fn (DatabaseNode $node) => $node->server->ensureSized());

            if ($data->accountsFrom !== null) {
                $database->copyAccountsFrom($data->accountsFrom);
            } else {
                $database->recordDefaultAccounts();
            }

            if ($data->recovery !== null) {
                $database->recordRecovery($data->recovery);
            }

            return $database;
        });

        dispatch(new CreateDatabaseJob($database));

        return $database;
    }
}
