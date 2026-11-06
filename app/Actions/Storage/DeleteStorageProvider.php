<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\StorageProvider;

final readonly class DeleteStorageProvider
{
    public function handle(StorageProvider $provider): void
    {
        $provider->ensureDeletable();

        $provider->delete();
    }
}
