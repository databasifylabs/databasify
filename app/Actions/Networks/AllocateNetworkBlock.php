<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Models\Network;
use App\Models\Server;
use App\Support\Networks\Cidr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

final readonly class AllocateNetworkBlock
{
    /**
     * @param  Collection<int, Server>  $memberServers
     */
    public function handle(int $blockPrefix, Collection $memberServers): string
    {
        $taken = $this->networkRanges()->merge($this->subnetsOn($memberServers));

        return $this->blocksOf($blockPrefix)->first(fn (string $block): bool => $taken->doesntContain(fn (string $range): bool => Cidr::overlaps($block, $range)))
            ?? throw ValidationException::withMessages(['network' => "No free /{$blockPrefix} block is left in ".Network::WIREGUARD_POOL.'. Choose a smaller block size.']);
    }

    /**
     * @return LazyCollection<int, non-falsy-string>
     */
    private function blocksOf(int $prefix): LazyCollection
    {
        $base = Cidr::start(Network::WIREGUARD_POOL);
        $size = Cidr::size($prefix);
        $count = intdiv(Cidr::length(Network::WIREGUARD_POOL), $size);

        return LazyCollection::range(0, $count - 1)->map(fn (int $block): string => long2ip($base + $block * $size)."/{$prefix}");
    }

    /**
     * @return Collection<int, string>
     */
    private function networkRanges(): Collection
    {
        return Network::query()->get(['cidr'])->toBase()->map(fn (Network $network): string => $network->cidr);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, string>
     */
    private function subnetsOn(Collection $servers): Collection
    {
        return $servers->flatMap(fn (Server $server): array => $server->interface_subnets?->all() ?? [])->values();
    }
}
