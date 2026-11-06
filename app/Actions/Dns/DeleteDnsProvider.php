<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\Models\DnsProvider;

final readonly class DeleteDnsProvider
{
    public function handle(DnsProvider $provider): void
    {
        $provider->ensureDeletable();

        $provider->delete();
    }
}
