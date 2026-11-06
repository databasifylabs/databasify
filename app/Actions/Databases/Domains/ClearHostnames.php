<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\Models\DnsZone;
use App\Services\Dns\DnsRecordPublisher;

final readonly class ClearHostnames
{
    public function __construct(private DnsRecordPublisher $dns) {}

    /**
     * @param  list<string>  $names
     */
    public function handle(DnsZone $zone, array $names): void
    {
        collect($names)->each(fn (string $name) => $this->dns->point($zone, $name, []));
    }
}
