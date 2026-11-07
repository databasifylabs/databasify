<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Models\ServerProvider;
use Illuminate\Support\Facades\DB;

final readonly class DeleteServerProvider
{
    public function handle(ServerProvider $provider): void
    {
        $provider->ensureDeletable();

        DB::transaction(fn () => $provider->delete());
    }
}
