<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\DnsProvider;

final readonly class DnsProviderObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(DnsProvider $provider): void
    {
        $this->children->handle($provider->zones());
    }
}
