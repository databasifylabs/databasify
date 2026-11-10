<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Actions\Databases\Flows\StartFlow;
use App\DTOs\Storage\NewStorageProvider;
use App\Engines\Flows\ArchiveFlow;
use App\Engines\Flows\FlowContext;
use App\Models\Database;
use App\Models\StorageProvider;

final readonly class UpdateStorageProvider
{
    public function __construct(
        private CheckStorageAccess $access,
        private StartFlow $startFlow,
        private ArchiveFlow $archiveFlow,
    ) {}

    public function handle(StorageProvider $provider, NewStorageProvider $data): StorageProvider
    {
        $archiving = $provider->archivingDatabases();
        $provider->ensureLocationKeptFor($data->bucket);
        $rotated = $provider->bucket()->credentials() !== $data->bucket->credentials();

        if ($rotated) {
            $archiving->each(fn (Database $database) => $database->ensureIdle());
        }

        $this->access->handle($data->bucket);
        $provider->update($data->toVerifiedRow(now()));

        if ($rotated) {
            $archiving->each(function (Database $database): void {
                $this->startFlow->handle($database, $this->archiveFlow, new FlowContext([]));
                $database->retitleOperation('Update archive credentials');
            });
        }

        return $provider;
    }
}
