<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\DTOs\Storage\NewStorageProvider;
use App\Models\StorageProvider;

final readonly class AddStorageProvider
{
    public function __construct(private CheckStorageAccess $access) {}

    public function handle(NewStorageProvider $data): StorageProvider
    {
        $this->access->handle($data->bucket);

        return StorageProvider::query()->create($data->toVerifiedRow(now()));
    }
}
