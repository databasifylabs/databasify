<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Models\StorageProvider;
use Illuminate\Support\Facades\DB;

final readonly class DeleteStorageProvider
{
    public function handle(StorageProvider $provider): void
    {
        $provider->ensureDeletable();

        DB::transaction(fn () => $provider->delete());
    }
}
