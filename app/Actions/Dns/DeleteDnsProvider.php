<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\Models\DnsProvider;
use Illuminate\Support\Facades\DB;

final readonly class DeleteDnsProvider
{
    public function handle(DnsProvider $provider): void
    {
        $provider->ensureDeletable();

        DB::transaction(fn () => $provider->delete());
    }
}
