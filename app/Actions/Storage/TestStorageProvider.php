<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\StorageProvider;

final readonly class TestStorageProvider
{
    public function __construct(private CheckStorageAccess $access) {}

    public function handle(StorageProvider $provider): void
    {
        $this->access->handle($provider->bucket());
        $provider->markVerified();
    }
}
