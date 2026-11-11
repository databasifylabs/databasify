<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\DTOs\Databases\NewDatabase;
use App\DTOs\Recovery\PointInTimeRestore;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use Illuminate\Validation\ValidationException;

final readonly class RestoreToPointInTime
{
    public function __construct(
        private RefreshRecoveryStatus $refresh,
        private CreateDatabase $create,
    ) {}

    public function handle(Database $source, PointInTimeRestore $data): Database
    {
        $recovery = $source->recoveryOrFail();
        $this->ensureRestorable($recovery, $data);

        return $this->create->handle(new NewDatabase(
            name: $data->name,
            engine: $source->engine,
            primaryServerId: $data->serverId,
            versionLine: $source->version_line,
            secrets: $source->settings->secrets,
            restore: $recovery->sourceAt($data->target),
            config: $source->settings->config,
            persistence: $source->settings->persistence,
            image: $source->runsSupportedSdk() ? $source->image() : null,
            accountsFrom: $source,
            recovery: $recovery->settings(),
        ));
    }

    private function ensureRestorable(PointInTimeRecovery $recovery, PointInTimeRestore $data): void
    {
        if ($recovery->mayReachAfterRefresh($data->target)) {
            $this->refresh->handle($recovery);
        }

        if (! $recovery->canRestoreTo($data->target)) {
            throw ValidationException::withMessages(['target' => "{$recovery->database->name} cannot be restored to {$data->target->format('Y-m-d H:i:s')} UTC. {$recovery->restorableWindowMessage()}"]);
        }
    }
}
