<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Models\ServerProvider;

final readonly class DeleteServerProvider
{
    public function handle(ServerProvider $provider): void
    {
        $provider->ensureDeletable();

        $provider->delete();
    }
}
