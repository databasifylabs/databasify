<?php

declare(strict_types=1);

namespace App\Services\Networks;

use App\Models\Network;
use App\Models\Server;
use App\Support\Networks\Cidr;
use Illuminate\Support\LazyCollection;
use RuntimeException;

final readonly class WireguardAddressAllocator
{
    public function next(Network $network, ?Server $except = null): string
    {
        $used = $network->servers()->whereKeyNot($except?->id)->whereNotNull('private_ip')->pluck('private_ip')->flip();

        return $this->hostsIn($network->cidr)->first(fn (string $address): bool => ! $used->has($address))
            ?? throw new RuntimeException("{$network->name} has no free address left.");
    }

    /**
     * @return LazyCollection<int, string>
     */
    private function hostsIn(string $cidr): LazyCollection
    {
        $network = Cidr::start($cidr);
        $broadcast = $network + Cidr::length($cidr) - 1;

        return LazyCollection::range($network + 1, $broadcast - 1)->map(fn (int $address): string => long2ip($address));
    }
}
