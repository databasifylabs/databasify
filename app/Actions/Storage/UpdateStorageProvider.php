<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Actions\Databases\Recovery\QueueRecoveryFiles;
use App\DTOs\Storage\NewStorageProvider;
use App\Models\Database;
use App\Models\StorageProvider;

final readonly class UpdateStorageProvider
{
    public function __construct(
        private CheckStorageAccess $access,
        private QueueRecoveryFiles $files,
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
            $archiving->each(fn (Database $database) => $this->files->handle($database, 'Update archive credentials'));
        }

        return $provider;
    }
}
