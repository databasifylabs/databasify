<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\StoredArchive;
use App\Models\StorageProvider;

final readonly class DeleteArchive
{
    public function handle(StoredArchive $archive): void
    {
        $provider = StorageProvider::query()->withoutProject()->findOrFail($archive->storageProviderId);

        $s3 = $provider->s3();
        $s3->deleteAllUnder($archive->prefix());
        $s3->deleteFolderIfEmpty($archive->folder());
    }
}
