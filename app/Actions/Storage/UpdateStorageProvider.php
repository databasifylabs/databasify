<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\DTOs\Storage\NewStorageProvider;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;
use App\Models\StorageProvider;

final readonly class UpdateStorageProvider
{
    public function __construct(
        private CheckStorageAccess $access,
    ) {}

    public function handle(StorageProvider $provider, NewStorageProvider $data): StorageProvider
    {
        $archiving = $provider->archivingDatabases();
        $provider->ensureLocationKeptFor($data->bucket);
        $rotated = $provider->bucket()->credentials() !== $data->bucket->credentials();

        if ($rotated) {
            $archiving->each(fn (Database $database) => $database->ensureNotBusy());
        }

        $this->access->handle($data->bucket);
        $provider->update($data->toVerifiedRow(now()));

        if ($rotated) {
            $archiving->each(function (Database $database): void {
                $database->markBusy(DatabaseStatus::Updating);
                dispatch(new ApplySettingsJob($database, archiveChanged: true));
            });
        }

        return $provider;
    }
}
